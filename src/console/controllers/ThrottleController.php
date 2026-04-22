<?php

namespace pixelwerft\useraudit\console\controllers;

use craft\console\Controller;
use pixelwerft\useraudit\UserAudit;
use yii\console\ExitCode;

/**
 * Manually unblocks a client locked out by throttling.
 *
 *   ./craft user-audit/throttle/reset --ip=1.2.3.4
 *   ./craft user-audit/throttle/reset --email=foo@bar.ch
 *   ./craft user-audit/throttle/reset --ip=1.2.3.4 --email=foo@bar.ch
 *
 * Deletes login_failed entries from the last windowMinutes for the
 * given IP and/or email. The rest of the audit archive stays
 * untouched.
 */
class ThrottleController extends Controller
{
    public $defaultAction = 'reset';

    public ?string $ip = null;
    public ?string $email = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'reset') {
            $options[] = 'ip';
            $options[] = 'email';
        }
        return $options;
    }

    public function actionReset(): int
    {
        $plugin = UserAudit::getInstance();
        if (!$plugin) {
            $this->stderr("[user-audit] plugin not available\n");
            return ExitCode::UNAVAILABLE;
        }

        if ($this->ip === null && $this->email === null) {
            $this->stderr("Usage: --ip=<ip> and/or --email=<email>\n");
            return ExitCode::USAGE;
        }

        /** @var \pixelwerft\useraudit\models\Settings $settings */
        $settings = $plugin->getSettings();
        $deleted = $plugin->activityLog->clearRecentFailures(
            $this->ip,
            $this->email,
            (int)$settings->windowMinutes
        );

        $this->stdout("[user-audit] cleared {$deleted} recent failure entries.\n");
        return ExitCode::OK;
    }
}
