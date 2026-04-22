<?php

namespace pixelwerft\useraudit\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Db;
use pixelwerft\useraudit\UserAudit;
use yii\console\ExitCode;

/**
 * Deletes old entries from {{%user_activity_log}} based on the
 * configured retentionDays setting.
 *
 * Invocation (manual or via cron):
 *   ./craft user-audit/purge/run
 *   ./craft user-audit/purge/run --dry-run=1
 *
 * Cron example (every 30 minutes):
 *   STAR/30 * * * * cd /path/to/craft && php craft user-audit/purge/run >/dev/null 2>&1
 *
 * When retentionDays = 0 (default) the action does nothing — the
 * user must first enable retention in the plugin settings.
 */
class PurgeController extends Controller
{
    public $defaultAction = 'run';

    /**
     * When true, only counts and reports how many rows would be
     * deleted — writes NOTHING. Useful for cron tests or manual checks.
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'run') {
            $options[] = 'dryRun';
        }
        return $options;
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'd' => 'dryRun',
        ]);
    }

    public function actionRun(): int
    {
        $plugin = UserAudit::getInstance();
        if (!$plugin) {
            $this->stderr("[user-audit] plugin not available\n");
            return ExitCode::UNAVAILABLE;
        }

        /** @var \pixelwerft\useraudit\models\Settings $settings */
        $settings = $plugin->getSettings();
        $days = (int)$settings->retentionDays;

        if ($days <= 0) {
            $this->stdout("[user-audit] retentionDays=0 → nothing to purge.\n");
            return ExitCode::OK;
        }

        // SQL-side cutoff: NOW - N days. We deliberately use the DB
        // server clock (not PHP time()) so the boundary stays
        // consistent across clustered PHP processes.
        $cutoff = (new \DateTime("-{$days} days"))->format('Y-m-d H:i:s');

        $db = Craft::$app->getDb();

        $count = (int)$db->createCommand(
            'SELECT COUNT(*) FROM {{%user_activity_log}} WHERE [[dateCreated]] < :cutoff',
            [':cutoff' => $cutoff]
        )->queryScalar();

        if ($count === 0) {
            $this->stdout("[user-audit] no entries older than {$days} days.\n");
            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout("[user-audit] DRY-RUN: would delete {$count} entries older than {$cutoff}.\n");
            return ExitCode::OK;
        }

        $deleted = $db->createCommand()
            ->delete('{{%user_activity_log}}', ['<', 'dateCreated', $cutoff])
            ->execute();

        $this->stdout("[user-audit] purged {$deleted} entries older than {$cutoff}.\n");
        Craft::info(
            "[user-audit] purge removed {$deleted} entries older than {$cutoff}",
            __CLASS__
        );

        return ExitCode::OK;
    }
}
