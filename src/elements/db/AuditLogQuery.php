<?php

namespace pixelwerft\useraudit\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use pixelwerft\useraudit\elements\AuditLog;
use pixelwerft\useraudit\services\ActivityLogService;

/**
 * Element query for AuditLog elements.
 *
 * Joins {{%user_activity_log}} on `elementId` so callers can filter by
 * any audit-row column (event type, context, IP, browser, etc.) the
 * same way Craft entry queries filter by section/type.
 *
 * Filter properties mirror the underlying schema 1:1 — anything
 * settable via this query maps directly to a WHERE on the joined
 * audit row, no field translation in between.
 */
class AuditLogQuery extends ElementQuery
{
    /** @var string|string[]|null */
    public mixed $eventType = null;

    /** @var string|string[]|null */
    public mixed $context = null;

    /** @var string|string[]|null */
    public mixed $client = null;

    /** @var int|int[]|null */
    public mixed $auditUserId = null;

    /** @var string|string[]|null */
    public mixed $email = null;

    /** @var string|string[]|null */
    public mixed $ipAddress = null;

    /** @var string|string[]|null */
    public mixed $browserName = null;

    /** @var string|string[]|null */
    public mixed $osName = null;

    /** @var string|string[]|null */
    public mixed $deviceType = null;

    /** @var string|string[]|null */
    public mixed $failureReason = null;

    /**
     * v2.3.0: filters rows to a single session id.
     *
     * NOTE — named `$sessionIdFilter`, not `$sessionId`, on purpose.
     * A property that shadows a parent ElementQuery property with a
     * stricter type triggers the PHP "Type of ... must be mixed"
     * compile error (the v2.2.4 `$search` lesson). `sessionId` does
     * not currently collide, but keeping filter props off the schema
     * column names sidesteps the whole class of problem — and the
     * paired setter reads naturally either way.
     *
     * @var string|string[]|null
     */
    public mixed $sessionIdFilter = null;

    /**
     * Free-text search across the same set of columns the legacy
     * controller used. Translates to an `OR LIKE` chain so existing
     * filter behaviour is preserved.
     *
     * NOTE — deliberately NOT named `$search`: Crafts `ElementQuery`
     * parent already declares `public mixed $search` for the Craft
     * search-index integration, and a stricter-typed shadow (e.g.
     * `?string`) triggers a PHP compile error (LSP violation):
     * *"Type of ... must be mixed (as in class craft\\elements\\db\\ElementQuery)"*.
     * Our free-text search does a plain SQL LIKE chain, not the
     * Craft search index, so we sidestep the name entirely.
     */
    public ?string $textSearch = null;

    public function eventType(mixed $value): self
    {
        $this->eventType = $value;
        return $this;
    }

    public function context(mixed $value): self
    {
        $this->context = $value;
        return $this;
    }

    public function client(mixed $value): self
    {
        $this->client = $value;
        return $this;
    }

    public function auditUserId(mixed $value): self
    {
        $this->auditUserId = $value;
        return $this;
    }

    public function email(mixed $value): self
    {
        $this->email = $value;
        return $this;
    }

    public function ipAddress(mixed $value): self
    {
        $this->ipAddress = $value;
        return $this;
    }

    public function browserName(mixed $value): self
    {
        $this->browserName = $value;
        return $this;
    }

    public function osName(mixed $value): self
    {
        $this->osName = $value;
        return $this;
    }

    public function deviceType(mixed $value): self
    {
        $this->deviceType = $value;
        return $this;
    }

    public function failureReason(mixed $value): self
    {
        $this->failureReason = $value;
        return $this;
    }

    public function sessionIdFilter(mixed $value): self
    {
        $this->sessionIdFilter = $value;
        return $this;
    }

    public function textSearch(?string $value): self
    {
        $this->textSearch = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        // Manual JOINs because we use a separate `elementId` FK on
        // user_activity_log instead of the Craft convention where the
        // element-typed table shares its PK with elements.id. The
        // separate FK was chosen for v2.0 to keep existing audit-row
        // PKs stable through the migration. As a result we cannot use
        // the joinElementTable() helper.
        //
        // Inner subquery: pushes WHERE filters down before the outer
        // joins, so pagination/count stay fast.
        $this->subQuery->innerJoin(
            ['user_activity_log' => '{{%user_activity_log}}'],
            '[[user_activity_log.elementId]] = [[elements.id]]'
        );

        // Outer query: re-joins so addSelect() can pull audit columns
        // onto the populated element instance in a single round-trip.
        $this->query->innerJoin(
            ['user_activity_log' => '{{%user_activity_log}}'],
            '[[user_activity_log.elementId]] = [[subquery.elementsId]]'
        );

        $this->query->addSelect([
            'user_activity_log.userId AS auditUserId',
            'user_activity_log.email',
            'user_activity_log.userGroups',
            'user_activity_log.eventType',
            'user_activity_log.context',
            'user_activity_log.client',
            'user_activity_log.sessionId',
            'user_activity_log.failureReason',
            'user_activity_log.ipAddress',
            'user_activity_log.userAgent',
            'user_activity_log.deviceType',
            'user_activity_log.osName',
            'user_activity_log.osVersion',
            'user_activity_log.browserName',
            'user_activity_log.browserVersion',
            'user_activity_log.metadata',
            // Audit-side dateCreated may differ from elements.dateCreated
            // by a few ms — surface the audit-side one explicitly so
            // callers that care can read it.
            'user_activity_log.dateCreated AS auditDateCreated',
        ]);

        if ($this->eventType !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.eventType', $this->eventType));
        }
        if ($this->context !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.context', $this->context));
        }
        if ($this->client !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.client', $this->client));
        }
        if ($this->auditUserId !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.userId', $this->auditUserId));
        }
        if ($this->email !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.email', $this->email));
        }
        if ($this->ipAddress !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.ipAddress', $this->ipAddress));
        }
        if ($this->browserName !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.browserName', $this->browserName));
        }
        if ($this->osName !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.osName', $this->osName));
        }
        if ($this->deviceType !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.deviceType', $this->deviceType));
        }
        if ($this->failureReason !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.failureReason', $this->failureReason));
        }
        if ($this->sessionIdFilter !== null) {
            $this->subQuery->andWhere(Db::parseParam('user_activity_log.sessionId', $this->sessionIdFilter));
        }

        if ($this->textSearch !== null && trim($this->textSearch) !== '') {
            // OR LIKE chain across the same columns the v1 filter used.
            // userAgent stays excluded — TEXT-column LIKE is a guaranteed
            // bottleneck on big tables and the parsed browser/OS/device
            // columns cover what users actually search for.
            $needle = '%' . trim($this->textSearch) . '%';
            $this->subQuery->andWhere([
                'or',
                ['like', 'user_activity_log.email', $needle, false],
                ['like', 'user_activity_log.userGroups', $needle, false],
                ['like', 'user_activity_log.ipAddress', $needle, false],
                ['like', 'user_activity_log.browserName', $needle, false],
                ['like', 'user_activity_log.browserVersion', $needle, false],
                ['like', 'user_activity_log.osName', $needle, false],
                ['like', 'user_activity_log.osVersion', $needle, false],
                ['like', 'user_activity_log.deviceType', $needle, false],
                ['like', 'user_activity_log.failureReason', $needle, false],
                ['like', 'user_activity_log.eventType', $needle, false],
                ['like', 'user_activity_log.client', $needle, false],
            ]);
        }

        return parent::beforePrepare();
    }

    /**
     * Maps our event-type-derived statuses to WHERE clauses on the
     * audit row. Without this override Craft would fall back to the
     * default `enabled` column check, which would always be true for
     * audit rows and the status filter dropdown would do nothing.
     */
    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            AuditLog::STATUS_SUCCESS     => ['user_activity_log.eventType' => ActivityLogService::EVENT_LOGIN],
            AuditLog::STATUS_NEUTRAL     => ['user_activity_log.eventType' => ActivityLogService::EVENT_LOGOUT],
            AuditLog::STATUS_FAILURE     => ['user_activity_log.eventType' => ActivityLogService::EVENT_LOGIN_FAILED],
            AuditLog::STATUS_BLOCKED     => ['user_activity_log.eventType' => ActivityLogService::EVENT_LOGIN_BLOCKED],
            AuditLog::STATUS_EXPIRED     => ['user_activity_log.eventType' => ActivityLogService::EVENT_SESSION_EXPIRED],
            AuditLog::STATUS_PWD_CHANGED => ['user_activity_log.eventType' => ActivityLogService::EVENT_PASSWORD_CHANGED],
            AuditLog::STATUS_IMPERSONATION => ['user_activity_log.eventType' => [
                ActivityLogService::EVENT_IMPERSONATION_STARTED,
                ActivityLogService::EVENT_IMPERSONATION_STOPPED,
            ]],
            // "Other" = anything that's not one of the well-known
            // events. Lets custom-event entries surface in the index.
            AuditLog::STATUS_OTHER => ['not in', 'user_activity_log.eventType', [
                ActivityLogService::EVENT_LOGIN,
                ActivityLogService::EVENT_LOGOUT,
                ActivityLogService::EVENT_LOGIN_FAILED,
                ActivityLogService::EVENT_LOGIN_BLOCKED,
                ActivityLogService::EVENT_SESSION_EXPIRED,
                ActivityLogService::EVENT_PASSWORD_CHANGED,
                ActivityLogService::EVENT_IMPERSONATION_STARTED,
                ActivityLogService::EVENT_IMPERSONATION_STOPPED,
            ]],
            default => parent::statusCondition($status),
        };
    }
}
