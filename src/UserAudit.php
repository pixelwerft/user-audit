<?php

namespace pixelwerft\useraudit;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\User as UserElement;
use craft\events\LoginFailureEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\web\User as WebUser;
use craft\web\UrlManager;
use craft\web\twig\variables\Cp;
use pixelwerft\useraudit\elements\AuditLog;
use pixelwerft\useraudit\models\Settings;
use pixelwerft\useraudit\services\ActivityLogService;
use pixelwerft\useraudit\services\UserAgentParser;
use pixelwerft\useraudit\widgets\StatsWidget;
use yii\base\Event;
use yii\web\UserEvent;

/**
 * User Audit — records user activity (login, logout, failed logins
 * and arbitrary custom events) along with client metadata (IP,
 * device type, OS, browser) into a dedicated table.
 *
 * Core events are logged automatically via Craft hooks. For custom
 * events use the service API:
 *
 *   \pixelwerft\useraudit\UserAudit::getInstance()
 *     ->activityLog
 *     ->log('property_exported', $userId, ['metadata' => [...]]);
 *
 * @property-read ActivityLogService $activityLog
 * @property-read UserAgentParser $userAgentParser
 */
class UserAudit extends Plugin
{
    public string $schemaVersion = '2.0.0';
    public bool $hasCpSettings = true;

    /**
     * v2.3.0: Craft-session key under which the login hook stashes the
     * server-issued session id. BEFORE_LOGOUT reads it back to link
     * the logout row to its login row and compute the duration.
     */
    private const SESSION_ID_KEY = 'userAudit.sessionId';

    /**
     * v2.3.0: Craft-session key holding the impersonation marker
     * ({impersonatorId, impersonatedUserId}) set when an impersonation
     * starts, so the eventual return can be logged as
     * `impersonation_stopped`. Self-managed and confined to the
     * browser session — it never leaks across logins.
     */
    private const IMPERSONATION_KEY = 'userAudit.impersonation';

    public static function config(): array
    {
        return [
            'components' => [
                'activityLog' => ActivityLogService::class,
                'userAgentParser' => UserAgentParser::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Switch to the console namespace on console requests so
        // that `./craft user-audit/purge/run` finds the PurgeController.
        if (Craft::$app instanceof \craft\console\Application) {
            $this->controllerNamespace = 'pixelwerft\\useraudit\\console\\controllers';
        }

        $this->setComponents([
            'activityLog' => ActivityLogService::class,
            'userAgentParser' => UserAgentParser::class,
        ]);

        $this->registerEventHooks();
        $this->registerThrottling();
        $this->registerCpRoutes();
        $this->registerCpNav();
        $this->registerDashboardWidgets();
        $this->registerElementTypes();
    }

    /**
     * Access gate for the audit viewer UI (CP nav and controller).
     *
     * Admins always have access. In addition, users in any group whose
     * UID is listed in `allowedUserGroupUids` may access the viewer.
     * Everyone else is denied — the plugin is admin-only out of the box.
     */
    public function canAccess(?UserElement $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->admin) {
            return true;
        }

        /** @var Settings $settings */
        $settings = $this->getSettings();
        if (empty($settings->allowedUserGroupUids)) {
            return false;
        }

        try {
            foreach ($user->getGroups() as $group) {
                if (in_array((string)$group->uid, $settings->allowedUserGroupUids, true)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Group lookup failure must not escalate into a 500 on a
            // nav render — deny access and move on.
            return false;
        }

        return false;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'user-audit/settings',
            [
                'settings' => $this->getSettings(),
                // Fresh Settings instance so the template can surface
                // the class-declared defaults to the "Reset to defaults"
                // button without having to duplicate them in Twig.
                'defaults' => new Settings(),
            ]
        );
    }

    // ---------------------------------------------------------------------
    // Event registration
    // ---------------------------------------------------------------------
    //
    // All hooks are try/catch-wrapped — a failure when writing the audit
    // entry must never break the actual auth flow.

    /**
     * Returns the context of the current request ('cp' or 'fe').
     * Console requests return null — nothing to filter.
     */
    private function currentContext(): ?string
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof \craft\web\Request) return null;
        return $request->getIsCpRequest()
            ? ActivityLogService::CONTEXT_CP
            : ActivityLogService::CONTEXT_FE;
    }

    /**
     * Checks the record-* settings. Returns true if the event should
     * be logged. Console events (context=null) are always written —
     * the toggle settings only govern web requests.
     */
    /**
     * v2.2.3: Snapshot the given user's group handles as a comma-
     * separated string, or null if the user has no groups (or is
     * null). Shared by every auth hook that has a resolved User
     * identity so the audit log's `userGroups` column stays
     * consistent across login, logout, failed-login (when the login
     * name matched) and password-changed events — previously only
     * login logged groups, which made group-based filtering
     * misleading (login rows appeared, logout rows didn't).
     *
     * Snapshot semantics: reads the groups AT THE TIME OF THE EVENT.
     * If an admin changes a user's groups between their login and
     * their logout, the login row shows the old groups and the
     * logout row shows the new ones — that's a forensic feature,
     * not a bug.
     *
     * A getGroups() failure must never break the auth flow, so any
     * throwable is swallowed and treated as "no groups".
     */
    private function snapshotUserGroups(?UserElement $identity): ?string
    {
        if ($identity === null) {
            return null;
        }
        try {
            $handles = array_map(
                fn($g) => (string)$g->handle,
                $identity->getGroups()
            );
            if (empty($handles)) {
                return null;
            }
            return implode(',', $handles);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * v2.3.0: Classifies an AFTER_LOGIN event as a normal login, the
     * start of an impersonation, or the return from one.
     *
     * Detection:
     *  - START: Craft reports an impersonator for the current session
     *    (`getImpersonator()`) whose id differs from the identity now
     *    logged in — i.e. an admin just stepped into another account.
     *    A marker is stashed so the eventual return can be recognised.
     *  - STOP:  no impersonator is active, but our own marker is still
     *    present and the identity now logging in equals the stored
     *    impersonator — i.e. the admin returned to their own account.
     *    The marker is cleared.
     *
     * Both branches are guarded: a getImpersonator()/session failure
     * degrades to a plain `login` rather than breaking the auth flow.
     * The marker lives only inside the browser session and is cleared
     * on logout, so it can never produce a cross-session false event.
     *
     * @return array{0:string,1:array<string,mixed>} [eventType, extraMetadata]
     */
    private function classifyLoginEvent(?UserElement $identity): array
    {
        if ($identity === null) {
            return [ActivityLogService::EVENT_LOGIN, []];
        }

        $session = Craft::$app->getSession();

        $impersonator = null;
        try {
            $impersonator = Craft::$app->getUser()->getImpersonator();
        } catch (\Throwable) {
            // Treat an unavailable impersonator as "not impersonating".
        }

        if ($impersonator !== null && (int)$impersonator->id !== (int)$identity->id) {
            // Impersonation just started.
            try {
                $session->set(self::IMPERSONATION_KEY, [
                    'impersonatorId' => (int)$impersonator->id,
                    'impersonatedUserId' => (int)$identity->id,
                ]);
            } catch (\Throwable) {
                // Without the marker we simply can't log the later
                // return — the _started row is still written.
            }
            return [
                ActivityLogService::EVENT_IMPERSONATION_STARTED,
                ['impersonatorId' => (int)$impersonator->id],
            ];
        }

        // Not currently impersonating — is this a return?
        $marker = null;
        try {
            $marker = $session->get(self::IMPERSONATION_KEY);
        } catch (\Throwable) {
            // no session — fall through to plain login
        }
        if (
            is_array($marker)
            && isset($marker['impersonatorId'])
            && (int)$marker['impersonatorId'] === (int)$identity->id
        ) {
            try {
                $session->remove(self::IMPERSONATION_KEY);
            } catch (\Throwable) {
            }
            return [
                ActivityLogService::EVENT_IMPERSONATION_STOPPED,
                ['wasImpersonatingUserId' => (int)($marker['impersonatedUserId'] ?? 0)],
            ];
        }

        return [ActivityLogService::EVENT_LOGIN, []];
    }

    private function shouldRecord(?string $context): bool
    {
        if ($context === null) return true;
        /** @var \pixelwerft\useraudit\models\Settings $settings */
        $settings = $this->getSettings();
        if ($context === ActivityLogService::CONTEXT_CP) {
            return (bool)$settings->recordCpEvents;
        }
        return (bool)$settings->recordFrontendEvents;
    }

    private function registerEventHooks(): void
    {
        Event::on(
            WebUser::class,
            WebUser::EVENT_AFTER_LOGIN,
            function (UserEvent $event) {
                try {
                    $context = $this->currentContext();
                    if (!$this->shouldRecord($context)) return;

                    /** @var \craft\elements\User|null $identity */
                    $identity = $event->identity;
                    $userId = $identity ? (int)$identity->getId() : null;

                    // v2.3.0: classify the login as a normal login, an
                    // impersonation start, or an impersonation return.
                    // $extraMeta is merged into the row's metadata JSON.
                    [$eventType, $extraMeta] = $this->classifyLoginEvent($identity);

                    // New-location check BEFORE the audit write: the
                    // current login is not yet in the table, so
                    // isNewLocation() only sees prior entries. Only real
                    // logins trigger the alert — an admin impersonating a
                    // user must not email that user "new login detected".
                    $shouldAlert = false;
                    /** @var \pixelwerft\useraudit\models\Settings $settings */
                    $settings = $this->getSettings();
                    if (
                        $eventType === ActivityLogService::EVENT_LOGIN
                        && $settings->newLocationAlertsEnabled
                        && $userId !== null
                        && $identity !== null
                    ) {
                        $request = Craft::$app->getRequest();
                        $ip = $request instanceof \craft\web\Request
                            ? $request->getUserIP()
                            : null;
                        $shouldAlert = $this->activityLog->isNewLocation(
                            $userId,
                            $ip,
                            (int)$settings->newLocationLookbackDays
                        );
                    }

                    // Group snapshot: read once per login. Craft caches
                    // the relation internally, and we're after the
                    // login event — the user is already loaded anyway.
                    $groups = $this->snapshotUserGroups($identity);

                    // v2.3.0: issue a session id linking this login to
                    // its eventual logout row. Server-generated UUID,
                    // stashed in the Craft session so BEFORE_LOGOUT can
                    // read it back. A session-write failure must not
                    // break login — we still log the row (with the id)
                    // and simply lose the logout correlation.
                    $sessionId = \craft\helpers\StringHelper::UUID();
                    try {
                        Craft::$app->getSession()->set(self::SESSION_ID_KEY, $sessionId);
                    } catch (\Throwable $e) {
                        Craft::error(
                            '[user-audit] could not stash sessionId: ' . $e->getMessage(),
                            __CLASS__
                        );
                    }

                    // v2.3.0: suspicious-activity score — genuine
                    // logins only (not impersonation, which is
                    // admin-initiated and would score noisily). Five
                    // indexed sub-queries; failure must never break
                    // login, and a 0 score is left out of metadata so
                    // ordinary logins keep an empty metadata payload.
                    if ($eventType === ActivityLogService::EVENT_LOGIN && $userId !== null) {
                        try {
                            $request = Craft::$app->getRequest();
                            $ip = $request instanceof \craft\web\Request
                                ? $request->getUserIP()
                                : null;
                            $ua = $request instanceof \craft\web\Request
                                ? $request->getUserAgent()
                                : null;
                            $parsed = $this->userAgentParser->parse($ua);
                            $risk = $this->activityLog->computeRiskScore(
                                $userId,
                                $ip,
                                $parsed['deviceType'],
                                $parsed['browserName'],
                                $identity?->email
                            );
                            if ($risk['score'] > 0) {
                                $extraMeta['riskScore'] = $risk['score'];
                                $extraMeta['riskSignals'] = $risk['signals'];
                            }
                        } catch (\Throwable $e) {
                            Craft::error(
                                '[user-audit] risk score failed: ' . $e->getMessage(),
                                __CLASS__
                            );
                        }
                    }

                    $logMeta = [
                        'email' => $identity?->email,
                        'userGroups' => $groups,
                        'sessionId' => $sessionId,
                    ];
                    if ($extraMeta !== []) {
                        $logMeta['metadata'] = $extraMeta;
                    }

                    $this->activityLog->log($eventType, $userId, $logMeta);

                    if ($shouldAlert && $identity) {
                        $this->sendNewLocationMail($identity);
                    }
                } catch (\Throwable $e) {
                    Craft::error(
                        '[user-audit] after-login hook: ' . $e->getMessage(),
                        __CLASS__
                    );
                }
            }
        );

        // v2.3.0: BEFORE_LOGOUT, not AFTER. AFTER_LOGOUT fires *after*
        // the session has been destroyed, at which point the sessionId
        // we stashed at login is already gone — so the login↔logout
        // correlation (and thus the session duration) would be
        // impossible to compute. At BEFORE_LOGOUT both the identity and
        // the session data are still intact.
        Event::on(
            WebUser::class,
            WebUser::EVENT_BEFORE_LOGOUT,
            function (UserEvent $event) {
                try {
                    if (!$this->shouldRecord($this->currentContext())) return;

                    /** @var \craft\elements\User|null $identity */
                    $identity = $event->identity;

                    $meta = [
                        'email' => $identity?->email,
                        // v2.2.3: snapshot groups at logout too.
                        // `$event->identity` is still populated at
                        // BEFORE_LOGOUT.
                        'userGroups' => $this->snapshotUserGroups($identity),
                    ];

                    // v2.3.0: read the session id back and, if present,
                    // resolve the session duration from the login row.
                    // `session_expired` events never reach this hook, so
                    // the documented "no duration for expired sessions"
                    // rule holds automatically.
                    $sessionId = null;
                    try {
                        $sessionId = Craft::$app->getSession()->get(self::SESSION_ID_KEY);
                    } catch (\Throwable) {
                        // No session available (or already torn down) —
                        // leave the logout row without a duration.
                    }
                    if (is_string($sessionId) && $sessionId !== '') {
                        $meta['sessionId'] = $sessionId;
                        $duration = $this->activityLog->resolveSessionDuration($sessionId);
                        if ($duration !== null) {
                            $meta['metadata'] = ['sessionDurationSeconds' => $duration];
                        }
                    }

                    $this->activityLog->log(
                        ActivityLogService::EVENT_LOGOUT,
                        $identity ? (int)$identity->getId() : null,
                        $meta
                    );
                } catch (\Throwable $e) {
                    Craft::error(
                        '[user-audit] before-logout hook: ' . $e->getMessage(),
                        __CLASS__
                    );
                }
            }
        );

        // Failed login: Craft fires this in UsersController on every
        // unsuccessful attempt. `$event->user` is only set when the
        // login name matches an existing user (wrong password). If the
        // login name is unknown, user=null — in that case we read the
        // request body directly so we at least log WHAT was attempted.
        Event::on(
            \craft\controllers\UsersController::class,
            \craft\controllers\UsersController::EVENT_LOGIN_FAILURE,
            function (LoginFailureEvent $event) {
                try {
                    if (!$this->shouldRecord($this->currentContext())) return;

                    $request = Craft::$app->getRequest();
                    $loginName = $request instanceof \craft\web\Request
                        ? (string)$request->getBodyParam('loginName', '')
                        : '';

                    $this->activityLog->log(
                        ActivityLogService::EVENT_LOGIN_FAILED,
                        $event->user?->id !== null ? (int)$event->user->id : null,
                        [
                            'email' => $event->user?->email ?: ($loginName !== '' ? $loginName : null),
                            'failureReason' => $event->authError ?? $event->message,
                            // v2.2.3: when the login name matched an
                            // existing user, snapshot their groups too
                            // — makes group-based filtering ("who is
                            // being targeted?") work for failed logins
                            // as well. Stays null for unknown login
                            // names because there is no user to snapshot.
                            'userGroups' => $this->snapshotUserGroups($event->user),
                        ]
                    );
                } catch (\Throwable $e) {
                    Craft::error(
                        '[user-audit] login-failure hook: ' . $e->getMessage(),
                        __CLASS__
                    );
                }
            }
        );

        // ----------------------------------------------------------
        // Password changes (v2.2+)
        //
        // Two-phase hook because we need the still-plaintext password
        // (available on BEFORE_SAVE as $user->newPassword) to compute
        // strength flags, but we only want to write the audit row
        // once the save actually succeeded — Craft fires AFTER_SAVE
        // only on success, so the pending entry survives if the save
        // failed and gets discarded at request end with no log line.
        //
        // Self-changes vs admin-triggered: the BEFORE_SAVE handler
        // captures the actor (Craft::$app->user->identity) only when
        // it differs from the target. Self-changes pass triggeredBy
        // as null.
        // ----------------------------------------------------------
        Event::on(
            UserElement::class,
            UserElement::EVENT_BEFORE_SAVE,
            function (\craft\events\ModelEvent $event) {
                try {
                    /** @var UserElement $user */
                    $user = $event->sender;
                    if (empty($user->newPassword)) {
                        return;
                    }
                    /** @var Settings $settings */
                    $settings = $this->getSettings();
                    if (!$settings->recordPasswordChanges) {
                        return;
                    }
                    if ($user->id === null) {
                        // BEFORE_SAVE on a brand-new user fires before
                        // an id is assigned. AFTER_SAVE will have one;
                        // we can not key the pending map yet so we
                        // skip new-user creation. Initial passwords are
                        // re-captured the first time the user changes
                        // theirs through the activation flow.
                        return;
                    }

                    $flags = ActivityLogService::computePasswordStrengthFlags(
                        (string)$user->newPassword
                    );

                    $actor = Craft::$app->getUser()->getIdentity();
                    $triggeredBy = null;
                    if ($actor !== null && (int)$actor->id !== (int)$user->id) {
                        $triggeredBy = (int)$actor->id;
                    }

                    $this->activityLog->capturePendingPasswordStrength(
                        (int)$user->id,
                        $flags,
                        $triggeredBy
                    );
                } catch (\Throwable $e) {
                    Craft::error(
                        '[user-audit] before-save pwd hook: ' . $e->getMessage(),
                        __CLASS__
                    );
                }
            }
        );

        Event::on(
            UserElement::class,
            UserElement::EVENT_AFTER_SAVE,
            function (\craft\events\ModelEvent $event) {
                try {
                    /** @var UserElement $user */
                    $user = $event->sender;
                    if ($user->id === null) {
                        return;
                    }
                    $entry = $this->activityLog->flushPendingPasswordChange(
                        (int)$user->id
                    );
                    if ($entry === null) {
                        return;
                    }

                    $metadata = ['passwordStrength' => $entry['flags']];
                    if ($entry['triggeredBy'] !== null) {
                        $metadata['triggeredBy'] = $entry['triggeredBy'];
                    }

                    $this->activityLog->log(
                        ActivityLogService::EVENT_PASSWORD_CHANGED,
                        (int)$user->id,
                        [
                            'email' => $user->email,
                            // v2.2.3: consistency with login/logout —
                            // group snapshot on every event that has
                            // a User identity.
                            'userGroups' => $this->snapshotUserGroups($user),
                            'metadata' => $metadata,
                        ]
                    );
                } catch (\Throwable $e) {
                    Craft::error(
                        '[user-audit] after-save pwd hook: ' . $e->getMessage(),
                        __CLASS__
                    );
                }
            }
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                // Root and /logs both render the log list, so clicking
                // the main nav item lands on Logs.
                $event->rules['user-audit'] = 'user-audit/activity/index';
                $event->rules['user-audit/logs'] = 'user-audit/activity/index';
                $event->rules['user-audit/monitor'] = 'user-audit/activity/monitor';
                // Legacy alias — old bookmarks to the active-sessions
                // page keep working.
                $event->rules['user-audit/active'] = 'user-audit/activity/monitor';
                $event->rules['user-audit/export'] = 'user-audit/activity/export';
                // User trace: drill into a single user's activity.
                // The action receives userId via the action params,
                // so we constrain the URL token to digits and forward
                // it explicitly.
                $event->rules['user-audit/user/<userId:\d+>'] = 'user-audit/activity/user';
                // v2.0: read-only detail view for a single audit row.
                // Title-click in the element index opens this URL.
                $event->rules['user-audit/log/<elementId:\d+>'] = 'user-audit/activity/log';
                // v2.3.0: session overview — every row sharing one
                // sessionId (typically 1 login + 1 logout). The token
                // is UUID-shaped: lowercase hex plus hyphens only.
                $event->rules['user-audit/session/<sessionId:[a-f0-9\-]+>'] = 'user-audit/activity/session';
            }
        );
    }

    /**
     * Registers the AuditLog element type. v2.0+: every audit row has
     * a corresponding element so the standard CP element-index can
     * render the logs list with status pills, source-sidebar and
     * native search.
     */
    private function registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = AuditLog::class;
            }
        );
    }

    private function registerCpNav(): void
    {
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function (RegisterCpNavItemsEvent $event) {
                if (!$this->canAccess(Craft::$app->getUser()->getIdentity())) {
                    return;
                }

                $event->navItems[] = [
                    'label' => Craft::t('user-audit', 'User Audit'),
                    // Parent URL is the plugin root, not /logs. Craft
                    // picks "active parent" by prefix-matching the
                    // current path against this URL, so both
                    // /user-audit/logs and /user-audit/monitor count
                    // as inside the section and keep the subnav open.
                    // The root URL itself is routed to the Logs
                    // action via registerCpRoutes(), so clicking the
                    // main nav item still lands on Logs.
                    'url' => 'user-audit',
                    'icon' => '@pixelwerft/useraudit/icon-mask.svg',
                    'subnav' => [
                        'logs' => [
                            'label' => Craft::t('user-audit', 'Logs'),
                            'url' => 'user-audit/logs',
                        ],
                        'monitor' => [
                            'label' => Craft::t('user-audit', 'Monitor'),
                            'url' => 'user-audit/monitor',
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Sends the new-location mail to the user. Errors are caught and
     * logged — a broken mailer must never cause the login to fail.
     */
    private function sendNewLocationMail(\craft\elements\User $user): void
    {
        if (!$user->email) return;

        try {
            $request = Craft::$app->getRequest();
            $ip = $request instanceof \craft\web\Request
                ? $request->getUserIP()
                : null;
            $ua = $request instanceof \craft\web\Request
                ? $request->getUserAgent()
                : null;
            $parsed = $this->userAgentParser->parse($ua);

            /** @var \pixelwerft\useraudit\models\Settings $settings */
            $settings = $this->getSettings();

            $html = Craft::$app->getView()->renderTemplate(
                'user-audit/_mail/new_location',
                array_merge($parsed, [
                    'user' => $user,
                    'ip' => $ip,
                    'now' => new \DateTime(),
                    'lookbackDays' => $settings->newLocationLookbackDays,
                    'siteUrl' => \craft\helpers\UrlHelper::siteUrl(),
                ])
            );

            Craft::$app->getMailer()
                ->compose()
                ->setTo($user->email)
                ->setSubject(Craft::t('user-audit', 'Neuer Login auf deinem Konto'))
                ->setHtmlBody($html)
                ->send();
        } catch (\Throwable $e) {
            Craft::error(
                '[user-audit] new-location mail failed: ' . $e->getMessage(),
                __CLASS__
            );
        }
    }

    /**
     * Throttling: before the login action on UsersController we check
     * the fail count for IP and email within the sliding window.
     * Above threshold → 429 plus a login_blocked audit entry.
     *
     * We throw ONLY after writing the audit entry, so that the block
     * is visible in the dashboard (otherwise there's just the 429
     * response and no trace in the table).
     */
    private function registerThrottling(): void
    {
        Event::on(
            \craft\controllers\UsersController::class,
            \yii\base\Controller::EVENT_BEFORE_ACTION,
            function (\yii\base\ActionEvent $event) {
                if ($event->action->id !== 'login') return;

                /** @var \pixelwerft\useraudit\models\Settings $settings */
                $settings = $this->getSettings();
                if (!$settings->throttlingEnabled) return;

                // Do not log throttling events when the context is
                // disabled — consistent with the other hooks.
                if (!$this->shouldRecord($this->currentContext())) return;

                $request = Craft::$app->getRequest();
                if (!$request instanceof \craft\web\Request) return;

                $ip = $request->getUserIP();
                $loginName = trim((string)$request->getBodyParam('loginName', ''));

                $counts = $this->activityLog->countRecentFailures(
                    $ip,
                    $loginName !== '' ? $loginName : null,
                    (int)$settings->windowMinutes
                );

                $ipOver = $counts['ip'] >= $settings->failsPerIp && $settings->failsPerIp > 0;
                $emailOver = $counts['email'] >= $settings->failsPerEmail && $settings->failsPerEmail > 0;

                if (!$ipOver && !$emailOver) return;

                $reason = $ipOver ? 'rate_limit_ip' : 'rate_limit_email';
                $this->activityLog->log(
                    \pixelwerft\useraudit\services\ActivityLogService::EVENT_LOGIN_BLOCKED,
                    null,
                    [
                        'email' => $loginName !== '' ? $loginName : null,
                        'failureReason' => $reason,
                        'metadata' => [
                            'ipFailCount' => $counts['ip'],
                            'emailFailCount' => $counts['email'],
                            'windowMinutes' => $settings->windowMinutes,
                        ],
                    ]
                );

                $event->isValid = false;
                throw new \yii\web\TooManyRequestsHttpException(
                    Craft::t(
                        'user-audit',
                        'Too many failed login attempts. Please try again in a few minutes.'
                    )
                );
            }
        );
    }

    private function registerDashboardWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = StatsWidget::class;
            }
        );
    }
}
