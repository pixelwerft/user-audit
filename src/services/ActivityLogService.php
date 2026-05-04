<?php

namespace pixelwerft\useraudit\services;

use Craft;
use craft\base\Component;
use pixelwerft\useraudit\elements\AuditLog;
use pixelwerft\useraudit\records\UserActivityLog;
use pixelwerft\useraudit\UserAudit;

/**
 * Writes and reads entries in {{%user_activity_log}}.
 *
 * The central API is `log()`. Core events (login / logout /
 * login_failed / session_expired) are defined as constants, but
 * arbitrary strings are accepted — other modules/plugins can log
 * their own events against the same table (e.g. "property_exported",
 * "user_locked").
 */
class ActivityLogService extends Component
{
    public const EVENT_LOGIN = 'login';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_LOGIN_FAILED = 'login_failed';
    public const EVENT_LOGIN_BLOCKED = 'login_blocked';
    public const EVENT_SESSION_EXPIRED = 'session_expired';

    public const CONTEXT_CP = 'cp';
    public const CONTEXT_FE = 'fe';

    public const CLIENT_PWA = 'pwa';
    public const CLIENT_BROWSER = 'browser';

    // Header the PWA sets on login/logout/session-expired requests.
    // Anything else comes from a regular browser or the CP.
    public const CLIENT_HEADER = 'X-Reest-Client';

    /**
     * @param string        $eventType Core constant or an arbitrary string.
     * @param int|null      $userId    null on failed logins when the login
     *                                 name does not resolve to an existing
     *                                 user.
     * @param array<string,mixed> $meta {
     *   @var string|null      $email
     *   @var string|null      $failureReason
     *   @var string|null      $ipAddress      Override — otherwise from request.
     *   @var string|null      $userAgent      Override — otherwise from request.
     *   @var array|null       $metadata       Extra data (JSON-serialized).
     * }
     * @return bool true on success, false when the DB write fails. A
     *              failure here must NEVER break the actual auth flow,
     *              which is why the method is try/catch-wrapped.
     */
    public function log(string $eventType, ?int $userId = null, array $meta = []): bool
    {
        try {
            $request = Craft::$app->getRequest();
            $isWeb = $request instanceof \craft\web\Request;

            $ip = $meta['ipAddress'] ?? ($isWeb ? $request->getUserIP() : null);
            $ua = $meta['userAgent'] ?? ($isWeb ? $request->getUserAgent() : null);

            // Context: cp on CP requests, fe on front-end. Can be
            // overridden by the caller (e.g. custom events or when
            // a console context explicitly simulates an FE event).
            $context = $meta['context'] ?? null;
            if ($context === null && $isWeb) {
                $context = $request->getIsCpRequest()
                    ? self::CONTEXT_CP
                    : self::CONTEXT_FE;
            }

            $parsed = UserAudit::getInstance()->userAgentParser->parse($ua);

            // Client type: override from $meta wins, then the header,
            // then fall back to 'browser' only for FE requests. Leave
            // null for CP and console — the context suffices there.
            $client = $meta['client'] ?? null;
            /** @var \pixelwerft\useraudit\models\Settings $settings */
            $settings = UserAudit::getInstance()->getSettings();
            if ($client === null && $settings->recordClientType && $isWeb) {
                $headerValue = trim((string)$request->getHeaders()->get(self::CLIENT_HEADER, ''));
                if ($headerValue !== '') {
                    $client = strtolower($headerValue) === self::CLIENT_PWA
                        ? self::CLIENT_PWA
                        : self::CLIENT_BROWSER;
                } elseif ($context === self::CONTEXT_FE) {
                    $client = self::CLIENT_BROWSER;
                }
            }
            if (!$settings->recordClientType) {
                $client = null;
            }

            // userGroups as snapshot: comma-separated handles at login
            // time. Caller can override via $meta['userGroups'] (string
            // or string[]) — otherwise the field stays empty.
            $groups = $meta['userGroups'] ?? null;
            if (is_array($groups)) {
                $groups = implode(',', array_filter(array_map('strval', $groups)));
            }
            if (is_string($groups) && $groups !== '') {
                // Hard length cap matches the schema column.
                if (strlen($groups) > 512) {
                    $groups = substr($groups, 0, 512);
                }
            } else {
                $groups = null;
            }

            // v2.0+: each audit row is also a Craft element. Create
            // the element shell first to get an ID, then save the
            // audit record with elementId set. Wrapped in a single
            // transaction so a save-failure on either side leaves no
            // orphans behind.
            $db = Craft::$app->getDb();
            $transaction = $db->beginTransaction();
            try {
                $element = new AuditLog();
                if (!Craft::$app->getElements()->saveElement($element, false)) {
                    throw new \RuntimeException(
                        'AuditLog element save failed: '
                        . implode('; ', $element->getFirstErrors() ?: ['unknown'])
                    );
                }

                $record = new UserActivityLog();
                $record->elementId = (int)$element->id;
                $record->userId = $userId;
                $record->email = $meta['email'] ?? null;
                $record->userGroups = $groups;
                $record->eventType = $eventType;
                $record->context = $context;
                $record->client = $client;
                $record->failureReason = $meta['failureReason'] ?? null;
                $record->ipAddress = $ip;
                $record->userAgent = $ua;
                $record->deviceType = $parsed['deviceType'];
                $record->osName = $parsed['osName'];
                $record->osVersion = $parsed['osVersion'];
                $record->browserName = $parsed['browserName'];
                $record->browserVersion = $parsed['browserVersion'];

                if (isset($meta['metadata']) && is_array($meta['metadata'])) {
                    $record->metadata = json_encode(
                        $meta['metadata'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                }

                if (!$record->save()) {
                    throw new \RuntimeException(
                        'UserActivityLog record save failed: '
                        . implode('; ', $record->getFirstErrors() ?: ['unknown'])
                    );
                }

                $transaction->commit();
                return true;
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            Craft::error(
                '[user-audit] failed to write activity log: ' . $e->getMessage(),
                __CLASS__
            );
            return false;
        }
    }

    /**
     * Counts login_failed entries from the last N minutes for an IP
     * and/or a login name (email).
     *
     * Sliding window: cutoff is NOW - $windowMinutes; rows above that
     * no longer count. The caller (throttling check) compares the
     * count against the configured thresholds.
     */
    public function countRecentFailures(
        ?string $ip,
        ?string $email,
        int $windowMinutes
    ): array {
        $cutoff = (new \DateTime("-{$windowMinutes} minutes"))->format('Y-m-d H:i:s');
        $db = Craft::$app->getDb();

        $ipCount = 0;
        if ($ip !== null && $ip !== '') {
            $ipCount = (int)$db->createCommand(
                'SELECT COUNT(*) FROM {{%user_activity_log}}
                 WHERE [[eventType]] = :t
                   AND [[ipAddress]] = :ip
                   AND [[dateCreated]] >= :since',
                [':t' => self::EVENT_LOGIN_FAILED, ':ip' => $ip, ':since' => $cutoff]
            )->queryScalar();
        }

        $emailCount = 0;
        if ($email !== null && $email !== '') {
            $emailCount = (int)$db->createCommand(
                'SELECT COUNT(*) FROM {{%user_activity_log}}
                 WHERE [[eventType]] = :t
                   AND [[email]] = :email
                   AND [[dateCreated]] >= :since',
                [':t' => self::EVENT_LOGIN_FAILED, ':email' => $email, ':since' => $cutoff]
            )->queryScalar();
        }

        return ['ip' => $ipCount, 'email' => $emailCount];
    }

    /**
     * Deletes login_failed entries from the last N minutes for
     * IP/email, so the reset command can unlock a blocked user/client
     * without losing the entire audit archive. We only delete the
     * login_failed rows that filled the throttling window — all
     * other event types stay untouched.
     */
    /**
     * Checks whether this IP has never had a successful login for
     * this user within the lookback window. Called by the AFTER_LOGIN
     * hook BEFORE writing the current entry — the new entry is not
     * yet in the table.
     */
    public function isNewLocation(
        int $userId,
        ?string $ip,
        int $lookbackDays
    ): bool {
        if ($ip === null || $ip === '') return false;

        $cutoff = (new \DateTime("-{$lookbackDays} days"))->format('Y-m-d H:i:s');
        $count = (int)Craft::$app->getDb()->createCommand(
            'SELECT COUNT(*) FROM {{%user_activity_log}}
             WHERE [[eventType]] = :t
               AND [[userId]] = :uid
               AND [[ipAddress]] = :ip
               AND [[dateCreated]] >= :since',
            [
                ':t' => self::EVENT_LOGIN,
                ':uid' => $userId,
                ':ip' => $ip,
                ':since' => $cutoff,
            ]
        )->queryScalar();

        return $count === 0;
    }

    public function clearRecentFailures(
        ?string $ip,
        ?string $email,
        int $windowMinutes
    ): int {
        if (($ip === null || $ip === '') && ($email === null || $email === '')) {
            return 0;
        }

        $cutoff = (new \DateTime("-{$windowMinutes} minutes"))->format('Y-m-d H:i:s');
        $conditions = ['and',
            ['eventType' => self::EVENT_LOGIN_FAILED],
            ['>=', 'dateCreated', $cutoff],
        ];

        $or = ['or'];
        if ($ip) $or[] = ['ipAddress' => $ip];
        if ($email) $or[] = ['email' => $email];
        $conditions[] = $or;

        return (int)Craft::$app->getDb()->createCommand()
            ->delete('{{%user_activity_log}}', $conditions)
            ->execute();
    }
}
