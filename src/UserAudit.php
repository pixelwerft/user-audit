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
use craft\web\User as WebUser;
use craft\web\UrlManager;
use craft\web\twig\variables\Cp;
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
    public string $schemaVersion = '1.2.0';
    public bool $hasCpSettings = true;

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
            ['settings' => $this->getSettings()]
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

                    // New-location check BEFORE the audit write: the
                    // current login is not yet in the table, so
                    // isNewLocation() only sees prior entries.
                    $shouldAlert = false;
                    /** @var \pixelwerft\useraudit\models\Settings $settings */
                    $settings = $this->getSettings();
                    if (
                        $settings->newLocationAlertsEnabled
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
                    $groups = null;
                    if ($identity) {
                        try {
                            $handles = array_map(
                                fn($g) => (string)$g->handle,
                                $identity->getGroups()
                            );
                            if (!empty($handles)) {
                                $groups = implode(',', $handles);
                            }
                        } catch (\Throwable) {
                            // Group lookup must never break the auth
                            // flow. Continue without groups.
                        }
                    }

                    $this->activityLog->log(
                        ActivityLogService::EVENT_LOGIN,
                        $userId,
                        [
                            'email' => $identity?->email,
                            'userGroups' => $groups,
                        ]
                    );

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

        Event::on(
            WebUser::class,
            WebUser::EVENT_AFTER_LOGOUT,
            function (UserEvent $event) {
                try {
                    if (!$this->shouldRecord($this->currentContext())) return;

                    /** @var \craft\elements\User|null $identity */
                    $identity = $event->identity;
                    $this->activityLog->log(
                        ActivityLogService::EVENT_LOGOUT,
                        $identity ? (int)$identity->getId() : null,
                        ['email' => $identity?->email]
                    );
                } catch (\Throwable $e) {
                    Craft::error(
                        '[user-audit] after-logout hook: ' . $e->getMessage(),
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
                    // Main item target = Logs, so a click on the top
                    // nav item deterministically lands on the log list.
                    'url' => 'user-audit/logs',
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
