<?php

namespace pixelwerft\useraudit\models;

use craft\base\Model;

/**
 * Plugin settings.
 *
 * retentionDays: 0 = never purge, >0 = the purge/run console command
 * deletes entries older than this.
 *
 * Throttling: rate limit for failed logins. Sliding window:
 * X failures within windowMinutes → 429 until the old failures roll
 * out of the window. Tracked separately per IP and per email so an
 * attacker cannot lock out entire user accounts just by
 * try-and-fail (the IP limit hits first).
 */
class Settings extends Model
{
    public int $retentionDays = 0;

    // Who gets recorded? Default both on. If CP events are disabled,
    // admin logins (Craft backend) are skipped; if FE events are
    // disabled, all logins from the PWA/public site are skipped.
    // The skip logic lives in the hooks so no log entry is written —
    // filtering events after writing would be more expensive and
    // would distort purge and statistics queries.
    public bool $recordCpEvents = true;
    public bool $recordFrontendEvents = true;

    // Client type detection: reads X-Reest-Client from the request
    // header and persists 'pwa' or 'browser' in the client column.
    // Off → column stays NULL (no write), filter is hidden.
    public bool $recordClientType = true;

    // Password-change logging (v2.2+). When on, the BEFORE_SAVE /
    // AFTER_SAVE hooks on the User element capture password-strength
    // classifications (length ≥ 8, has upper/lower/digit/special)
    // and write a `password_changed` audit row. The plaintext
    // password is observed only to compute the booleans — never
    // stored, never sent off-instance.
    public bool $recordPasswordChanges = true;

    public bool $throttlingEnabled = false;
    public int $failsPerIp = 10;
    public int $failsPerEmail = 5;
    public int $windowMinutes = 15;

    // New-location alert: emails the user when they log in from an
    // IP that has never been seen for them within the lookback
    // window. Helps detect compromised accounts.
    public bool $newLocationAlertsEnabled = false;
    public int $newLocationLookbackDays = 90;

    // Access control: admins can always see the plugin. In addition,
    // selected CP user groups can be granted access via this list of
    // group UIDs. Empty array → admin-only (default). Group UIDs (not
    // IDs) are used so the setting survives project-config deploys
    // across environments.
    /** @var string[] */
    public array $allowedUserGroupUids = [];

    public function defineRules(): array
    {
        return [
            [[
                'retentionDays',
                'failsPerIp',
                'failsPerEmail',
                'windowMinutes',
                'newLocationLookbackDays',
            ], 'integer', 'min' => 0],
            [[
                'throttlingEnabled',
                'newLocationAlertsEnabled',
                'recordCpEvents',
                'recordFrontendEvents',
                'recordClientType',
                'recordPasswordChanges',
            ], 'boolean'],
            [['allowedUserGroupUids'], 'each', 'rule' => ['string']],
        ];
    }
}
