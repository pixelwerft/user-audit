<?php

namespace pixelwerft\useraudit\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User as UserElement;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use pixelwerft\useraudit\elements\db\AuditLogQuery;
use pixelwerft\useraudit\services\ActivityLogService;
use pixelwerft\useraudit\UserAudit;

/**
 * Element wrapper around a {{%user_activity_log}} row.
 *
 * Why an Element at all? It buys us Crafts standard CP element-index
 * UX for free — source-sidebar with quick filters, status pills, sortable
 * sticky-header table, slideout detail, native search, ajax pagination.
 * The underlying audit table stays exactly as it was; the element row
 * lives in {{%elements}} and is linked to the audit row via
 * `user_activity_log.elementId`.
 *
 * Element status mirrors the event type:
 *   login           → success (green)
 *   logout          → neutral (gray)
 *   login_failed    → failure (orange)
 *   login_blocked   → blocked (red)
 *   session_expired → expired (blue)
 *   anything else   → other   (fuchsia)
 *
 * Audit logs are intentionally immutable from the UI:
 *   - canSave()   = false  (no edit form, no in-place changes)
 *   - canDelete() = false  (UI cannot trash or hard-delete)
 * Soft-delete only via the retention cron `purge/run`; hard-delete only
 * via the explicit console command `purge/hard --before=… --user-id=…`.
 */
class AuditLog extends Element
{
    public const STATUS_SUCCESS     = 'success';
    public const STATUS_NEUTRAL     = 'neutral';
    public const STATUS_FAILURE     = 'failure';
    public const STATUS_BLOCKED     = 'blocked';
    public const STATUS_EXPIRED     = 'expired';
    public const STATUS_PWD_CHANGED = 'pwd_changed';
    public const STATUS_IMPERSONATION = 'impersonation';
    public const STATUS_OTHER       = 'other';

    // ------------------------------------------------------------------
    // Audit-row columns mirrored onto the element instance.
    //
    // Populated by AuditLogQuery::beforePrepare() via aliased SELECT
    // additions on top of the elements join. `auditUserId` and
    // `auditDateCreated` use the audit-side source explicitly to avoid
    // colliding with the elements table's own `userId` / `dateCreated`
    // columns.
    // ------------------------------------------------------------------

    public ?int $auditUserId = null;
    public ?string $email = null;
    public ?string $userGroups = null;
    public string $eventType = '';
    public ?string $context = null;
    public ?string $client = null;
    public ?string $sessionId = null;
    public ?string $failureReason = null;
    public ?string $ipAddress = null;
    public ?string $userAgent = null;
    public ?string $deviceType = null;
    public ?string $osName = null;
    public ?string $osVersion = null;
    public ?string $browserName = null;
    public ?string $browserVersion = null;
    public ?string $metadata = null;
    public ?string $auditDateCreated = null;

    // ------------------------------------------------------------------
    // Identity
    // ------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('user-audit', 'Audit log');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('user-audit', 'audit log');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('user-audit', 'Audit logs');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('user-audit', 'audit logs');
    }

    public static function refHandle(): ?string
    {
        return 'auditLog';
    }

    public static function trackChanges(): bool
    {
        // Audit rows are immutable; tracking change-counters on the
        // elements table would be wasted writes.
        return false;
    }

    public static function hasContent(): bool
    {
        // No field layout, no content table.
        return false;
    }

    public static function hasTitles(): bool
    {
        // We synthesize a UI label from event type + IP via getUiLabel().
        return false;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function isLocalized(): bool
    {
        // Audit data is global per install — not site-scoped.
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_SUCCESS     => ['label' => Craft::t('user-audit', 'Login'),       'color' => 'green'],
            self::STATUS_NEUTRAL     => ['label' => Craft::t('user-audit', 'Logout'),      'color' => 'gray'],
            self::STATUS_FAILURE     => ['label' => Craft::t('user-audit', 'Failed'),      'color' => 'orange'],
            self::STATUS_BLOCKED     => ['label' => Craft::t('user-audit', 'Blocked'),     'color' => 'red'],
            self::STATUS_EXPIRED     => ['label' => Craft::t('user-audit', 'Expired'),     'color' => 'blue'],
            self::STATUS_PWD_CHANGED => ['label' => Craft::t('user-audit', 'Pwd changed'), 'color' => 'violet'],
            self::STATUS_IMPERSONATION => ['label' => Craft::t('user-audit', 'Impersonation'), 'color' => 'amber'],
            self::STATUS_OTHER       => ['label' => Craft::t('user-audit', 'Other'),       'color' => 'fuchsia'],
        ];
    }

    public function getStatus(): ?string
    {
        return match ($this->eventType) {
            ActivityLogService::EVENT_LOGIN            => self::STATUS_SUCCESS,
            ActivityLogService::EVENT_LOGOUT           => self::STATUS_NEUTRAL,
            ActivityLogService::EVENT_LOGIN_FAILED     => self::STATUS_FAILURE,
            ActivityLogService::EVENT_LOGIN_BLOCKED    => self::STATUS_BLOCKED,
            ActivityLogService::EVENT_SESSION_EXPIRED  => self::STATUS_EXPIRED,
            ActivityLogService::EVENT_PASSWORD_CHANGED => self::STATUS_PWD_CHANGED,
            ActivityLogService::EVENT_IMPERSONATION_STARTED => self::STATUS_IMPERSONATION,
            ActivityLogService::EVENT_IMPERSONATION_STOPPED => self::STATUS_IMPERSONATION,
            default                                    => self::STATUS_OTHER,
        };
    }

    public function getUiLabel(): string
    {
        // Synthesized "title" for places Craft expects a label
        // (slideout heading, breadcrumbs, action labels). Includes the
        // bits a glance needs: what happened + from where.
        $bits = [$this->eventType !== '' ? $this->eventType : 'event'];
        if ($this->ipAddress) {
            $bits[] = '@ ' . $this->ipAddress;
        }
        return implode(' ', $bits);
    }

    // ------------------------------------------------------------------
    // Element query
    // ------------------------------------------------------------------

    public static function find(): ElementQueryInterface
    {
        return new AuditLogQuery(static::class);
    }

    // ------------------------------------------------------------------
    // CP Element Index — sources, table, sort, search
    // ------------------------------------------------------------------

    protected static function defineSources(string $context = null): array
    {
        return [
            ['heading' => Craft::t('user-audit', 'All')],
            [
                'key'         => '*',
                'label'       => Craft::t('user-audit', 'All events'),
                'criteria'    => [],
                'defaultSort' => ['auditDateCreated', 'desc'],
            ],
            ['heading' => Craft::t('user-audit', 'By event')],
            [
                'key'      => 'event:login',
                'label'    => Craft::t('user-audit', 'Logins'),
                'criteria' => ['eventType' => ActivityLogService::EVENT_LOGIN],
            ],
            [
                'key'      => 'event:logout',
                'label'    => Craft::t('user-audit', 'Logouts'),
                'criteria' => ['eventType' => ActivityLogService::EVENT_LOGOUT],
            ],
            [
                'key'      => 'event:login_failed',
                'label'    => Craft::t('user-audit', 'Failed logins'),
                'criteria' => ['eventType' => ActivityLogService::EVENT_LOGIN_FAILED],
            ],
            [
                'key'      => 'event:login_blocked',
                'label'    => Craft::t('user-audit', 'Blocked logins'),
                'criteria' => ['eventType' => ActivityLogService::EVENT_LOGIN_BLOCKED],
            ],
            [
                'key'      => 'event:session_expired',
                'label'    => Craft::t('user-audit', 'Session expired'),
                'criteria' => ['eventType' => ActivityLogService::EVENT_SESSION_EXPIRED],
            ],
            [
                'key'      => 'event:password_changed',
                'label'    => Craft::t('user-audit', 'Password changes'),
                'criteria' => ['eventType' => ActivityLogService::EVENT_PASSWORD_CHANGED],
            ],
            [
                'key'      => 'event:impersonation',
                'label'    => Craft::t('user-audit', 'Impersonation'),
                'criteria' => ['eventType' => [
                    ActivityLogService::EVENT_IMPERSONATION_STARTED,
                    ActivityLogService::EVENT_IMPERSONATION_STOPPED,
                ]],
            ],
            ['heading' => Craft::t('user-audit', 'By context')],
            [
                'key'      => 'context:cp',
                'label'    => Craft::t('user-audit', 'CP / Backend'),
                'criteria' => ['context' => ActivityLogService::CONTEXT_CP],
            ],
            [
                'key'      => 'context:fe',
                'label'    => Craft::t('user-audit', 'Frontend'),
                'criteria' => ['context' => ActivityLogService::CONTEXT_FE],
            ],
            ['heading' => Craft::t('user-audit', 'Archive')],
            [
                'key'      => 'trashed',
                'label'    => Craft::t('user-audit', 'Soft-deleted'),
                'criteria' => ['trashed' => true],
            ],
        ];
    }

    protected static function defineActions(string $source = null): array
    {
        // No bulk actions in v2.0 MVP. Hard-delete is console-only;
        // soft-delete is policy-driven via the purge cron. Selection
        // export will return as a dedicated action once the CSV
        // controller knows how to receive a selection-id list.
        return [];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'auditDateCreated' => ['label' => Craft::t('user-audit', 'Time')],
            'eventType'        => ['label' => Craft::t('user-audit', 'Event')],
            'context'          => ['label' => Craft::t('user-audit', 'Context')],
            'client'           => ['label' => Craft::t('user-audit', 'Client')],
            'auditUserId'      => ['label' => Craft::t('user-audit', 'User')],
            'email'            => ['label' => Craft::t('user-audit', 'Email / Login')],
            'userGroups'       => ['label' => Craft::t('user-audit', 'Groups')],
            'ipAddress'        => ['label' => Craft::t('user-audit', 'IP')],
            'deviceType'       => ['label' => Craft::t('user-audit', 'Device')],
            'osName'           => ['label' => Craft::t('user-audit', 'OS')],
            'browserName'      => ['label' => Craft::t('user-audit', 'Browser')],
            'failureReason'    => ['label' => Craft::t('user-audit', 'Reason')],
            // v2.3: risk score — off by default, opt-in via the column
            // picker. Read from the row's metadata JSON.
            'riskScore'        => ['label' => Craft::t('user-audit', 'Risk')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        // Visible on first load; users can toggle the rest via the
        // index column picker and Craft remembers the preference.
        return [
            'auditDateCreated',
            'eventType',
            'context',
            'auditUserId',
            'email',
            'ipAddress',
            'browserName',
            'failureReason',
        ];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'auditDateCreated' => Craft::t('user-audit', 'Time'),
            [
                'label'     => Craft::t('user-audit', 'Event'),
                'orderBy'   => 'eventType',
                'attribute' => 'eventType',
            ],
            [
                'label'     => Craft::t('user-audit', 'Email'),
                'orderBy'   => 'email',
                'attribute' => 'email',
            ],
            [
                'label'     => Craft::t('user-audit', 'IP'),
                'orderBy'   => 'ipAddress',
                'attribute' => 'ipAddress',
            ],
            [
                'label'     => Craft::t('user-audit', 'Browser'),
                'orderBy'   => 'browserName',
                'attribute' => 'browserName',
            ],
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        // v2.1: empty by default — the v2.1 list UI uses a hand-rolled
        // filter form that hits the audit table directly via SQL LIKE,
        // not Crafts search index. Indexing every audit row would make
        // each ActivityLogService::log() write fire a search-index
        // job and pile load onto the DB without giving the UI anything
        // to read back. If a future v2.x reintroduces the element-index
        // UI, restore the column list documented below.
        //
        //   Suggested set:
        //   ['email', 'userGroups', 'ipAddress', 'browserName',
        //    'osName', 'deviceType', 'failureReason', 'eventType']
        return [];
    }

    /**
     * @inheritdoc
     */
    protected function attributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'auditDateCreated':
                if (!$this->auditDateCreated) {
                    return '<span class="light">—</span>';
                }
                $dt = new \DateTime($this->auditDateCreated);
                return Html::tag('span', $dt->format('Y-m-d H:i:s'), ['class' => 'light']);

            case 'eventType':
                return Html::tag('code', Html::encode($this->eventType));

            case 'context':
                if ($this->context === ActivityLogService::CONTEXT_CP) {
                    return Html::tag('span', 'CP', [
                        'style' => 'padding: 0.1rem 0.4rem; border-radius: 3px; background: #e0e4ff; font-size: 0.7rem;',
                    ]);
                }
                if ($this->context === ActivityLogService::CONTEXT_FE) {
                    return Html::tag('span', 'FE', [
                        'style' => 'padding: 0.1rem 0.4rem; border-radius: 3px; background: #e0ffe4; font-size: 0.7rem;',
                    ]);
                }
                return '<span class="light">—</span>';

            case 'client':
                if ($this->client === ActivityLogService::CLIENT_PWA) {
                    return Html::tag('span', 'PWA', [
                        'style' => 'padding: 0.1rem 0.4rem; border-radius: 3px; background: #ffe8cc; font-size: 0.7rem;',
                    ]);
                }
                if ($this->client === ActivityLogService::CLIENT_BROWSER) {
                    return Html::tag('span', Craft::t('user-audit', 'Browser'), [
                        'style' => 'padding: 0.1rem 0.4rem; border-radius: 3px; background: #f0f0f0; font-size: 0.7rem;',
                    ]);
                }
                return '<span class="light">—</span>';

            case 'auditUserId':
                if (!$this->auditUserId) {
                    return '—';
                }
                $url = UrlHelper::cpUrl('user-audit/user/' . $this->auditUserId);
                return Html::a((string)$this->auditUserId, $url, [
                    'title' => Craft::t('user-audit', 'Show all activity for this user'),
                ]);

            case 'email':
                if (!$this->email) {
                    return '—';
                }
                if ($this->auditUserId) {
                    $url = UrlHelper::cpUrl('user-audit/user/' . $this->auditUserId);
                    return Html::a(Html::encode($this->email), $url, [
                        'title' => Craft::t('user-audit', 'Show all activity for this user'),
                    ]);
                }
                return Html::encode($this->email);

            case 'userGroups':
                if (!$this->userGroups) {
                    return '<span class="light">—</span>';
                }
                $html = '';
                foreach (explode(',', $this->userGroups) as $g) {
                    $html .= Html::tag('span', Html::encode(trim($g)), [
                        'style' => 'padding: 0.1rem 0.4rem; border-radius: 3px; background: #eef; font-size: 0.7rem; margin-right: 0.15rem;',
                    ]);
                }
                return $html;

            case 'osName':
                if (!$this->osName) {
                    return '—';
                }
                return Html::encode($this->osName . ($this->osVersion ? ' ' . $this->osVersion : ''));

            case 'browserName':
                if (!$this->browserName) {
                    return '—';
                }
                return Html::encode($this->browserName . ($this->browserVersion ? ' ' . $this->browserVersion : ''));

            case 'riskScore':
                // v2.3: read the score out of the metadata JSON. Only
                // login rows carry one, and only when it was >= 1.
                if (!$this->metadata) {
                    return '<span class="light">—</span>';
                }
                $meta = json_decode($this->metadata, true);
                $score = is_array($meta) ? ($meta['riskScore'] ?? null) : null;
                if ($score === null) {
                    return '<span class="light">—</span>';
                }
                $high = (int)$score >= 3;
                return Html::tag('span', '⚠ ' . (int)$score, [
                    'style' => 'padding: 0.05rem 0.4rem; border-radius: 3px; font-size: 0.75rem; font-weight: 600;'
                        . ($high ? 'background: #fee2e2; color: #b91c1c;' : 'background: #fef3c7; color: #b45309;'),
                    'title' => is_array($meta) && !empty($meta['riskSignals'])
                        ? implode(', ', (array)$meta['riskSignals'])
                        : '',
                ]);

            default:
                $value = $this->$attribute ?? null;
                if ($value === null || $value === '') {
                    return '—';
                }
                return Html::encode((string)$value);
        }
    }

    // ------------------------------------------------------------------
    // Element edit screen / slideout
    // ------------------------------------------------------------------

    public function getCpEditUrl(): ?string
    {
        // Title-click in the index opens this URL — routed by
        // UserAudit::registerCpRoutes() to a custom controller action
        // that renders the read-only detail slideout.
        return UrlHelper::cpUrl('user-audit/log/' . $this->id);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        // No editable fields. Returning null keeps Craft from
        // rendering any field-tab UI in the editor.
        return null;
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function canView(UserElement $user): bool
    {
        return UserAudit::getInstance()->canAccess($user);
    }

    public function canSave(UserElement $user): bool
    {
        // Audit logs are immutable. No save path from the UI.
        return false;
    }

    public function canDelete(UserElement $user): bool
    {
        // UI delete is intentionally disabled — even for admins.
        // Soft-delete: only via the retention cron `purge/run`.
        // Hard-delete: only via `./craft user-audit/purge/hard`
        // with explicit filters.
        return false;
    }

    public function canDuplicate(UserElement $user): bool
    {
        return false;
    }
}
