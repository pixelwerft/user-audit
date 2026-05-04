<?php

namespace pixelwerft\useraudit\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use pixelwerft\useraudit\elements\AuditLog;
use pixelwerft\useraudit\UserAudit;
use yii\console\ExitCode;
use yii\db\Query;

/**
 * Retention and explicit-deletion commands for the audit log.
 *
 * Two distinct commands with very different semantics:
 *
 *   ./craft user-audit/purge/run [--dry-run=1]
 *     Soft-delete (Craft element trash). Marks rows older than the
 *     configured retentionDays as deleted but keeps them in the
 *     database. They disappear from the default index but stay
 *     queryable via the "Soft-deleted" source and remain exportable
 *     via the CSV export with a `deleted_at` marker. This is the
 *     compliance-aware default — nothing physically gone, only
 *     archived.
 *
 *   ./craft user-audit/purge/hard --before=YYYY-MM-DD [--user-id=N] [--dry-run=1]
 *     Hard-delete: physically removes element + audit row. Requires
 *     at least one filter (--before or --user-id) so an admin cannot
 *     accidentally wipe the entire table. Prompts for confirmation
 *     before deleting unless --interactive=0 is passed (e.g. inside
 *     scripted DSGVO-removal pipelines).
 *
 * The retention `purge/run` cron runs unattended; `purge/hard` is
 * always operator-driven.
 */
class PurgeController extends Controller
{
    public $defaultAction = 'run';

    /**
     * Both commands accept --dry-run: count what would be touched
     * but write nothing. Useful for cron tests and pre-flight checks.
     */
    public bool $dryRun = false;

    /** Hard-delete only: cutoff date, ISO format `YYYY-MM-DD`. */
    public ?string $before = null;

    /** Hard-delete only: restrict to a single user id. */
    public ?int $userId = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'run') {
            $options[] = 'dryRun';
        }
        if ($actionID === 'hard') {
            $options[] = 'dryRun';
            $options[] = 'before';
            $options[] = 'userId';
        }
        return $options;
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'd' => 'dryRun',
            'b' => 'before',
            'u' => 'userId',
        ]);
    }

    /**
     * Soft-delete pass: move audit rows older than retentionDays into
     * Crafts element trash. v2.0+ semantics — v1 hard-deleted rows;
     * v2 keeps them around behind a `dateDeleted` flag.
     */
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

        $cutoff = (new \DateTime("-{$days} days"))->format('Y-m-d H:i:s');

        // Find elements to soft-delete: live (not yet trashed) audit
        // rows older than the cutoff.
        $elementIds = (new Query())
            ->select(['user_activity_log.elementId'])
            ->from('{{%user_activity_log}} user_activity_log')
            ->innerJoin(
                '{{%elements}} elements',
                '[[elements.id]] = [[user_activity_log.elementId]]'
            )
            ->where(['<', 'user_activity_log.dateCreated', $cutoff])
            ->andWhere(['elements.dateDeleted' => null])
            ->column();

        $count = count($elementIds);

        if ($count === 0) {
            $this->stdout("[user-audit] no live entries older than {$days} days.\n");
            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout("[user-audit] DRY-RUN: would soft-delete {$count} entries older than {$cutoff}.\n");
            return ExitCode::OK;
        }

        $elementsService = Craft::$app->getElements();
        $deleted = 0;
        foreach ($elementIds as $id) {
            $element = AuditLog::find()
                ->id((int)$id)
                ->status(null) // include any status
                ->one();
            if ($element === null) {
                continue;
            }
            if ($elementsService->deleteElement($element, false)) {
                $deleted++;
            }
        }

        $this->stdout("[user-audit] soft-deleted {$deleted} of {$count} entries older than {$cutoff}.\n");
        Craft::info(
            "[user-audit] purge/run soft-deleted {$deleted} entries older than {$cutoff}",
            __CLASS__
        );

        return ExitCode::OK;
    }

    /**
     * Hard-delete pass: physically removes element + audit row.
     * Requires at least one of `--before=YYYY-MM-DD` or `--user-id=N`
     * to prevent accidental table-wipes.
     */
    public function actionHard(): int
    {
        if ($this->before === null && $this->userId === null) {
            $this->stderr(
                "[user-audit] purge/hard requires at least one filter:\n"
                . "  --before=YYYY-MM-DD   delete entries strictly older than this date\n"
                . "  --user-id=N           delete only entries for this user id\n"
                . "Combine both for AND-semantics. Refusing to delete the entire table.\n"
            );
            return ExitCode::USAGE;
        }

        $cutoffStr = null;
        if ($this->before !== null) {
            try {
                $dt = new \DateTime($this->before);
            } catch (\Throwable) {
                $this->stderr("[user-audit] --before must be a parseable date (YYYY-MM-DD).\n");
                return ExitCode::USAGE;
            }
            // Use start-of-day for the cutoff so `--before=2026-01-01`
            // means "everything strictly before that date", excluding
            // 2026-01-01 itself.
            $dt->setTime(0, 0, 0);
            $cutoffStr = $dt->format('Y-m-d H:i:s');
        }

        // Build the candidate query (live + trashed both — hard delete
        // is policy-driven and must be able to clear archive too).
        $candidateQuery = (new Query())
            ->select(['user_activity_log.elementId', 'user_activity_log.userId', 'user_activity_log.dateCreated'])
            ->from('{{%user_activity_log}} user_activity_log');
        if ($cutoffStr !== null) {
            $candidateQuery->andWhere(['<', 'user_activity_log.dateCreated', $cutoffStr]);
        }
        if ($this->userId !== null) {
            $candidateQuery->andWhere(['user_activity_log.userId' => $this->userId]);
        }

        $candidateCount = (int)$candidateQuery->count('*', Craft::$app->getDb());

        if ($candidateCount === 0) {
            $this->stdout("[user-audit] no entries match the given filters.\n");
            return ExitCode::OK;
        }

        $describe = [];
        if ($cutoffStr !== null) {
            $describe[] = "older than {$cutoffStr}";
        }
        if ($this->userId !== null) {
            $describe[] = "for userId={$this->userId}";
        }
        $describeStr = implode(' AND ', $describe);

        if ($this->dryRun) {
            $this->stdout(
                "[user-audit] DRY-RUN: would hard-delete {$candidateCount} entries {$describeStr}.\n"
                . "Note: hard-deleted rows are NOT recoverable and will not appear in any subsequent CSV export.\n"
            );
            return ExitCode::OK;
        }

        if ($this->interactive) {
            $this->stdout(
                "[user-audit] About to PERMANENTLY delete {$candidateCount} audit entries {$describeStr}.\n"
                . "After this point the data is GONE — no soft-delete, no recovery, no CSV trace.\n"
                . "If you need a compliance trail, run a CSV export of the same selection before continuing.\n"
            );
            if (!$this->confirm('Proceed?')) {
                $this->stdout("[user-audit] aborted by user.\n");
                return ExitCode::OK;
            }
        }

        $elementsService = Craft::$app->getElements();
        $rows = $candidateQuery->all(Craft::$app->getDb());
        $deleted = 0;
        foreach ($rows as $r) {
            $elementId = (int)$r['elementId'];
            // Use AuditLog::find() with trashed:null so we can pick up
            // both live and already-soft-deleted rows for hard-deletion.
            $element = AuditLog::find()
                ->id($elementId)
                ->status(null)
                ->trashed(null)
                ->one();
            if ($element === null) {
                continue;
            }
            // hardDelete=true bypasses the trash and physically removes.
            // FK CASCADE drops the audit row alongside the element.
            if ($elementsService->deleteElement($element, true)) {
                $deleted++;
            }
        }

        $this->stdout("[user-audit] hard-deleted {$deleted} of {$candidateCount} entries.\n");
        Craft::info(
            "[user-audit] purge/hard removed {$deleted} entries ({$describeStr})",
            __CLASS__
        );

        return ExitCode::OK;
    }
}
