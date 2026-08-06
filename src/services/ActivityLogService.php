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
    public const EVENT_PASSWORD_CHANGED = 'password_changed';
    // v2.3.0: an admin started/stopped acting as another user via
    // Craft's impersonation. `_started` fires on the impersonation
    // login (getImpersonator() !== null); `_stopped` on the return.
    public const EVENT_IMPERSONATION_STARTED = 'impersonation_started';
    public const EVENT_IMPERSONATION_STOPPED = 'impersonation_stopped';

    public const CONTEXT_CP = 'cp';
    public const CONTEXT_FE = 'fe';

    public const CLIENT_PWA = 'pwa';
    public const CLIENT_BROWSER = 'browser';

    // Header the PWA sets on login/logout/session-expired requests.
    // Anything else comes from a regular browser or the CP.
    public const CLIENT_HEADER = 'X-Reest-Client';

    /**
     * Per-request, in-memory map for the password-change flow.
     * BEFORE_SAVE on the User element computes strength flags from
     * the still-plaintext $user->newPassword and parks them here
     * keyed by user id. AFTER_SAVE pops the entry and writes the
     * audit row — at that point the plaintext is already hashed and
     * gone from the User instance.
     *
     * Critical: this map only ever holds derived boolean flags +
     * integer length/score. The plaintext password never enters it.
     *
     * @var array<int, array{flags: array<string,bool|int>, triggeredBy: int|null}>
     */
    private array $pendingPasswordChanges = [];

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
     *   @var string|null      $sessionId      v2.3+ server-issued UUID that ties
     *                                          a login row to its logout row.
     *                                          Persisted to its own column, NOT
     *                                          into the metadata JSON.
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

                // v2.3.0: session-lifecycle id. Persisted as its own
                // column (not into the metadata JSON) so the logout
                // hook can resolve the matching login row via an
                // indexed lookup. The value only ever originates from
                // StringHelper::UUID() in the login hook — never from
                // user input — so the 36-char column cannot overflow
                // and silently drop the row under MySQL strict mode.
                if (isset($meta['sessionId']) && is_string($meta['sessionId']) && $meta['sessionId'] !== '') {
                    $record->sessionId = $meta['sessionId'];
                }

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

    // ------------------------------------------------------------------
    // Session lifecycle (v2.3+)
    // ------------------------------------------------------------------

    /**
     * v2.3.0: Resolves how long the session behind $sessionId has been
     * alive, in whole seconds, by locating its originating login row
     * and subtracting that timestamp from now.
     *
     * Called by the BEFORE_LOGOUT hook, which reads the sessionId back
     * out of the Craft session and asks for the duration to stash in
     * the logout row's `metadata.sessionDurationSeconds`.
     *
     * Returns null when no originating row is found — e.g. the login
     * write itself failed (swallowed by log()'s try/catch), or this is
     * a session id issued before the feature shipped. Callers MUST
     * treat null as "unknown duration" and not crash.
     *
     * Timezone: audit rows are stored in UTC. We parse the raw column
     * value as UTC via DateTimeHelper (assumeSystemTimeZone = false)
     * and compare epoch timestamps, so the result is correct even on
     * installs whose system/app timezone is not UTC — a plain
     * strtotime() on the naive string would be off by the TZ offset.
     */
    public function resolveSessionDuration(string $sessionId): ?int
    {
        if ($sessionId === '') {
            return null;
        }

        // A session is opened either by a normal login or by an
        // impersonation_started row (an admin stepping into another
        // user's account) — both deserve a duration on logout.
        $loginRow = UserActivityLog::find()
            ->where([
                'sessionId' => $sessionId,
                'eventType' => [self::EVENT_LOGIN, self::EVENT_IMPERSONATION_STARTED],
            ])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->one();

        if ($loginRow === null) {
            return null;
        }

        $start = \craft\helpers\DateTimeHelper::toDateTime(
            (string)$loginRow->dateCreated,
            false,
            false
        );
        if ($start === false) {
            return null;
        }

        // Clamp at 0: clock skew or a DB/PHP timezone edge must never
        // yield a negative duration that would break gmdate() downstream.
        return max(0, time() - $start->getTimestamp());
    }

    // ------------------------------------------------------------------
    // Password-change strength flow (v2.2+)
    // ------------------------------------------------------------------
    //
    // Privacy contract: the plaintext password is observed in exactly
    // one place — `computePasswordStrengthFlags()` — and only long
    // enough to derive 5 boolean classifications and an integer
    // length. The string is never stored, copied, hashed, returned,
    // logged or sent off-instance. Callers MUST NOT cache or pass the
    // plaintext anywhere else. The `meta` parameter on log() is also
    // not allowed to carry the plaintext.

    /**
     * Derives strength classifications from a plaintext password.
     *
     * One pass over the string, classify each character via Unicode
     * `\p{Lu}` / `\p{Ll}` regex tests plus a digit and whitespace
     * check. The "special" class is "anything that is not a letter
     * and not a digit and not whitespace", which covers the typical
     * punctuation and Unicode-symbol classes without committing to a
     * fixed allow-list. Loop bails out as soon as all four classes
     * have been seen, so long passwords stay cheap.
     *
     * @return array{length:int, meetsMin8:bool, hasUpper:bool, hasLower:bool, hasDigit:bool, hasSpecial:bool, score:int}
     */
    public static function computePasswordStrengthFlags(string $plaintext): array
    {
        $length = mb_strlen($plaintext);
        $meetsMin8 = $length >= 8;

        $hasUpper = $hasLower = $hasDigit = $hasSpecial = false;
        $len = $length;
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($plaintext, $i, 1);
            if (preg_match('/^\p{Lu}$/u', $ch)) {
                $hasUpper = true;
            } elseif (preg_match('/^\p{Ll}$/u', $ch)) {
                $hasLower = true;
            } elseif (preg_match('/^\d$/', $ch)) {
                $hasDigit = true;
            } elseif (preg_match('/^\s$/u', $ch)) {
                // whitespace doesn't count toward "special"
            } else {
                $hasSpecial = true;
            }
            if ($hasUpper && $hasLower && $hasDigit && $hasSpecial) {
                // Early exit — we already know all four classes are
                // present, no need to keep scanning a long password.
                break;
            }
        }

        $score = ($meetsMin8 ? 1 : 0)
            + ($hasUpper ? 1 : 0)
            + ($hasLower ? 1 : 0)
            + ($hasDigit ? 1 : 0)
            + ($hasSpecial ? 1 : 0);

        return compact(
            'length',
            'meetsMin8',
            'hasUpper',
            'hasLower',
            'hasDigit',
            'hasSpecial',
            'score'
        );
    }

    /**
     * Parks the strength flags for a pending password change.
     * Called from the User::EVENT_BEFORE_SAVE hook in UserAudit.
     *
     * @param array<string,bool|int> $flags Output of computePasswordStrengthFlags()
     * @param int|null               $triggeredBy User id of the actor causing
     *                                            the change when it differs
     *                                            from the target user (e.g.
     *                                            an admin resetting someone
     *                                            else's password). null for
     *                                            self-changes.
     */
    public function capturePendingPasswordStrength(
        int $userId,
        array $flags,
        ?int $triggeredBy = null
    ): void {
        $this->pendingPasswordChanges[$userId] = [
            'flags' => $flags,
            'triggeredBy' => $triggeredBy,
        ];
    }

    /**
     * Pops and returns a previously captured strength record, or
     * null if no pending change exists for this user. The popped
     * entry is removed from the map so a subsequent save in the
     * same request without a new password does not double-log.
     *
     * @return array{flags: array<string,bool|int>, triggeredBy: int|null}|null
     */
    public function flushPendingPasswordChange(int $userId): ?array
    {
        $entry = $this->pendingPasswordChanges[$userId] ?? null;
        unset($this->pendingPasswordChanges[$userId]);
        return $entry;
    }
}
