<?php

namespace pixelwerft\useraudit\controllers;

use Craft;
use craft\web\Controller;
use pixelwerft\useraudit\elements\AuditLog;
use pixelwerft\useraudit\records\UserActivityLog;
use pixelwerft\useraudit\services\ActivityLogService;
use pixelwerft\useraudit\UserAudit;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP controller for the audit viewer UI.
 *
 * Admin actions (index/active/export) are admin-only by default.
 * CP user groups listed under the plugin's `allowedUserGroupUids`
 * setting are granted the same access. The user-facing endpoints
 * (session-expired, my-recent) are intentionally open to the user
 * themselves.
 */
class ActivityController extends Controller
{
    // session-expired: anonymous (the PWA can no longer be
    // authenticated when its session expires). my-recent: logged-in
    // user viewing their own history (no viewer access required).
    // Everything else: admin or whitelisted CP group.
    protected array|int|bool $allowAnonymous = ['session-expired'];

    public $enableCsrfValidation = true;

    public function beforeAction($action): bool
    {
        // Disable CSRF for session-expired: the PWA fires this event
        // once its local session cookie has become invalid — the
        // associated CSRF token usually no longer matches either.
        // The endpoint is low-risk (only writes a log entry, no
        // state change on the user).
        if ($action->id === 'session-expired') {
            $this->enableCsrfValidation = false;
        }

        if (!parent::beforeAction($action)) {
            return false;
        }

        // Viewer gate only for the admin-facing actions.
        //   - session-expired: anonymous (user just logged out)
        //   - my-recent: logged-in user views their own history
        if (!in_array($action->id, ['session-expired', 'my-recent'], true)) {
            $user = Craft::$app->getUser()->getIdentity();
            if (!UserAudit::getInstance()->canAccess($user)) {
                throw new ForbiddenHttpException(
                    Craft::t('user-audit', 'You are not permitted to view the User Audit.')
                );
            }
        }

        if ($action->id === 'my-recent') {
            $this->requireLogin();
        }

        return true;
    }

    /**
     * v2.1: walked back from the v2.0 element-index UX. The custom
     * filter bar at the top + single-table query keeps the page snappy
     * even on big audit tables (Crafts element-index does multiple
     * joins on `elements`, `elements_sites` and `user_activity_log`
     * per request, which becomes the dominant cost at 100k+ rows).
     *
     * The element layer underneath (AuditLog element, elementId FK,
     * Craft-Trash soft-delete, hard-purge console command, CSV
     * `deleted_at`) stays in place — only the list UI is back to
     * v1.x style with the v2.0 status-pill column added.
     */
    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $perPage = 50;
        $eventType = (string)$request->getQueryParam('event', '');
        $context = (string)$request->getQueryParam('context', '');
        $client = (string)$request->getQueryParam('client', '');
        $q = trim((string)$request->getQueryParam('q', ''));
        $sort = (string)$request->getQueryParam('sort', 'dateCreated');
        $dir = strtolower((string)$request->getQueryParam('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        // v2.1: opt-in checkbox to include soft-deleted (rotated)
        // rows. Off by default so the index focuses on live activity.
        $includeArchive = (string)$request->getQueryParam('archive', '') === '1';

        // Live filter: only apply the search string from 2 characters
        // onwards, so individual keystrokes don't immediately trigger
        // a full table scan.
        $effectiveQ = mb_strlen($q) >= 2 ? $q : '';

        $query = $this->buildQuery($eventType, $effectiveQ, $context, $client, $sort, $dir);

        // Soft-delete filter: LEFT JOIN elements so we can read
        // dateDeleted onto the row (for the "Archive" badge in the
        // table) and exclude trashed rows from the default view.
        //
        // Important: the second `[[...]]` block must contain a raw
        // table reference (e.g. `user_activity_log.elementId`), not
        // the `{{%...}}` form. Yii's quoter unwraps `[[col]]` and
        // `{{%table}}` separately and refuses to nest them, which
        // would leave the literal `[[`user_activity_log`.elementId]]`
        // string in the SQL and trigger a syntax error.
        $rawTable = Craft::$app->getDb()->getSchema()->getRawTableName('{{%user_activity_log}}');
        $query->leftJoin(
            '{{%elements}} elements',
            "[[elements.id]] = [[{$rawTable}.elementId]]"
        );
        $query->addSelect(['elementDateDeleted' => '[[elements.dateDeleted]]']);
        if (!$includeArchive) {
            $query->andWhere(['elements.dateDeleted' => null]);
        }

        $total = (int)$query->count();
        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return $this->renderTemplate('user-audit/index', [
            'title' => Craft::t('user-audit', 'User audit logs'),
            'selectedSubnavItem' => 'logs',
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int)ceil($total / $perPage)),
            'eventType' => $eventType,
            'context' => $context,
            'client' => $client,
            'q' => $q,
            'sort' => $sort,
            'dir' => $dir,
            'includeArchive' => $includeArchive,
        ]);
    }

    /**
     * v2.0: read-only detail view for a single audit row.
     *
     * Reached by clicking the title in the element index (via
     * AuditLog::getCpEditUrl()). Renders the full row — including
     * the parsed UA, raw UA string and JSON metadata — plus a button
     * to the user-trace dashboard for the row's user, when set.
     */
    public function actionLog(int $elementId): Response
    {
        $element = AuditLog::find()
            ->id($elementId)
            ->status(null)
            ->trashed(null)
            ->one();

        if ($element === null) {
            throw new NotFoundHttpException(
                Craft::t('user-audit', 'Audit log entry not found.')
            );
        }

        // Pull dateDeleted off the elements row so the detail page
        // can show "Soft-deleted on …" — Craft already loaded it as
        // part of the element instance's trashed-state, but having
        // the explicit timestamp at hand is friendlier in the view.
        $dateDeleted = null;
        $row = (new \yii\db\Query())
            ->select(['dateDeleted'])
            ->from('{{%elements}}')
            ->where(['id' => $elementId])
            ->one();
        if ($row && !empty($row['dateDeleted'])) {
            $dateDeleted = $row['dateDeleted'];
        }

        return $this->renderTemplate('user-audit/log', [
            'title' => Craft::t('user-audit', 'Audit log entry'),
            'element' => $element,
            'dateDeleted' => $dateDeleted,
        ]);
    }

    /**
     * Valid monitor time-window keys. Anything else in the URL is
     * silently snapped to the default ('24h').
     */
    private const MONITOR_RANGES = ['24h', '48h', '7d', 'month', '30d', '90d'];

    /**
     * Chart color per event type — one line per type, the swatch next
     * to each event checkbox matches the line in the graph. Keep the
     * keys aligned with ActivityLogService::EVENT_* constants.
     */
    private const MONITOR_EVENT_COLORS = [
        'login'           => '#16a34a', // green
        'logout'          => '#64748b', // slate
        'login_failed'    => '#ea580c', // orange
        'login_blocked'   => '#dc2626', // red
        'session_expired' => '#0ea5e9', // sky
    ];

    /** Decorative swatch colors for the context checkbox dropdown. */
    private const MONITOR_CONTEXT_COLORS = [
        'cp' => '#6366f1',
        'fe' => '#06b6d4',
    ];

    /** Decorative swatch colors for the client checkbox dropdown. */
    private const MONITOR_CLIENT_COLORS = [
        'pwa'     => '#f59e0b',
        'browser' => '#64748b',
    ];

    /**
     * Monitor: pure time-series dashboard. Renders a bucketed line
     * chart of event activity for the selected range, filterable by
     * event type, context and client — the same dropdowns the log
     * list offers. Deliberately no log rows on this page; it's meant
     * to answer "how did activity look" at a glance, not "what
     * exactly happened".
     *
     * Bucketing strategy:
     *   24h / 48h         → one bar per hour
     *   7d / month / 30d  → one bar per day
     *   90d               → one bar per day (≈ 90 bars)
     *
     * "month" is calendar-bounded (1st of the current month → today);
     * every other range is a trailing window anchored at "now".
     */
    public function actionMonitor(): Response
    {
        $request = Craft::$app->getRequest();
        $range = (string)$request->getQueryParam('range', '');

        $allEventTypes = array_keys(self::MONITOR_EVENT_COLORS);
        $allContexts = array_keys(self::MONITOR_CONTEXT_COLORS);
        $allClients = array_keys(self::MONITOR_CLIENT_COLORS);

        // Filters are multi-select: URLs carry ?event[]=login&event[]=logout etc.
        // A bare ?event=login string is also accepted for backwards compat.
        $eventTypes = $this->multiParam($request, 'event', $allEventTypes);
        $contexts = $this->multiParam($request, 'context', $allContexts);
        $clients = $this->multiParam($request, 'client', $allClients);

        // Sentinel: the filter form always posts `_filters=1`. Its
        // absence means "URL was opened without the form being
        // submitted" (first visit, a bookmark to /monitor, a link
        // from elsewhere) — in that case we default to "everything
        // selected" so the dashboard is immediately useful. A real
        // submit with no boxes checked IS respected and produces an
        // empty chart.
        $filtersSubmitted = $request->getQueryParam('_filters') === '1';
        if (!$filtersSubmitted) {
            $eventTypes = $allEventTypes;
            $contexts = $allContexts;
            $clients = $allClients;
        }

        // Legacy support: pre-1.5 URLs carried ?hours=. Map them to the
        // nearest canonical range so old bookmarks keep working.
        if ($range === '' && $request->getQueryParam('hours') !== null) {
            $legacy = max(1, (int)$request->getQueryParam('hours', 24));
            $range = match (true) {
                $legacy <= 24 => '24h',
                $legacy <= 48 => '48h',
                $legacy <= 168 => '7d',
                $legacy <= 720 => '30d',
                default => '90d',
            };
        }

        if (!in_array($range, self::MONITOR_RANGES, true)) {
            $range = '24h';
        }

        $useDaily = !in_array($range, ['24h', '48h'], true);
        $now = new \DateTime();

        // Anchor = end of the plotted window, rounded down to the
        // current bucket so the most recent bar is fully populated.
        // Start = beginning of the window.
        $anchor = clone $now;
        if ($useDaily) {
            $anchor->setTime(0, 0);
        } else {
            $anchor->setTime((int)$anchor->format('H'), 0);
        }

        switch ($range) {
            case '48h':
                $start = (clone $anchor)->modify('-47 hours');
                break;
            case '7d':
                $start = (clone $anchor)->modify('-6 days');
                break;
            case 'month':
                $start = new \DateTime('first day of this month 00:00');
                break;
            case '30d':
                $start = (clone $anchor)->modify('-29 days');
                break;
            case '90d':
                $start = (clone $anchor)->modify('-89 days');
                break;
            case '24h':
            default:
                $start = (clone $anchor)->modify('-23 hours');
                break;
        }

        $bucketFormat = $useDaily ? 'Y-m-d' : 'Y-m-d H';
        $cutoffStr = $start->format('Y-m-d H:i:s');

        // Bucket skeleton with shared x-axis labels — every series
        // re-uses this structure so they line up pixel-perfect.
        $bucketMeta = [];
        $cursor = clone $start;
        while ($cursor <= $anchor) {
            $key = $cursor->format($bucketFormat);
            $bucketMeta[$key] = [
                'key' => $key,
                'label' => $cursor->format($useDaily ? 'M j' : 'H:00'),
                // Richer label shown in the hover tooltip — never
                // truncated by axis spacing.
                'tooltipLabel' => $cursor->format($useDaily ? 'D, M j' : 'M j, H:00'),
                'iso' => $cursor->format('c'),
            ];
            $cursor->modify($useDaily ? '+1 day' : '+1 hour');
        }

        // Per-series counts. Index: [eventType][bucketKey] = int.
        // Initialize all selected event types with zeroed buckets so
        // a series with no data still plots a flat zero line instead
        // of disappearing entirely.
        $seriesCounts = [];
        foreach ($eventTypes as $et) {
            $seriesCounts[$et] = array_fill_keys(array_keys($bucketMeta), 0);
        }

        // Fetch dateCreated + eventType; bucket in PHP so the SQL
        // stays portable across MySQL/MariaDB/Postgres. Short-circuit
        // when a dimension is empty (= user explicitly unchecked
        // everything in that dropdown) — that state legitimately
        // means "show nothing".
        if ($eventTypes && $contexts && $clients) {
            $query = (new \yii\db\Query())
                ->select(['dateCreated', 'eventType'])
                ->from('{{%user_activity_log}}')
                ->andWhere(['>=', 'dateCreated', $cutoffStr]);

            // Only add an IN-clause when the selection actually narrows
            // the result. "All options selected" is semantically
            // identical to "no filter" — in particular it must include
            // rows with NULL in that column, which an IN(...) silently
            // excludes. context and client are the common NULL columns
            // (CP-only / console-only rows don't have a client).
            if (count($eventTypes) < count($allEventTypes)) {
                $query->andWhere(['eventType' => $eventTypes]);
            }
            if (count($contexts) < count($allContexts)) {
                $query->andWhere(['context' => $contexts]);
            }
            if (count($clients) < count($allClients)) {
                $query->andWhere(['client' => $clients]);
            }

            foreach ($query->each(1000) as $row) {
                $key = (new \DateTime($row['dateCreated']))->format($bucketFormat);
                $et = $row['eventType'];
                if (isset($seriesCounts[$et][$key])) {
                    $seriesCounts[$et][$key]++;
                }
            }
        }

        // Overall max across ALL series for a shared y-axis — without
        // it, each line would auto-scale independently and "looks big"
        // would be misleading.
        $maxCount = 0;
        foreach ($seriesCounts as $counts) {
            $localMax = $counts ? max($counts) : 0;
            if ($localMax > $maxCount) $maxCount = $localMax;
        }

        // Legacy consumer (x-axis labels, widow-hint text) expects a
        // single list of bucket meta. Attach the total across series
        // for the tooltip summary row.
        $bucketList = [];
        foreach ($bucketMeta as $key => $meta) {
            $total = 0;
            foreach ($seriesCounts as $counts) {
                $total += $counts[$key] ?? 0;
            }
            $bucketList[] = $meta + ['count' => $total];
        }

        // SVG chart geometry — rendered at a fixed viewBox, CSS scales
        // it responsively. Extra left padding leaves room for Y-axis
        // tick labels; the rest keeps curves off the edges so stroke
        // weight stays visible at the extremes.
        $chartWidth = 960;
        $chartHeight = 240;
        $padLeft = 38;
        $padRight = 16;
        $padY = 16;
        $plotWidth = $chartWidth - $padLeft - $padRight;
        $plotHeight = $chartHeight - 2 * $padY;
        $baseY = $chartHeight - $padY;
        $effectiveMax = max(1, $maxCount);
        $n = count($bucketList);

        // Build one series per selected event type. X coordinates are
        // shared across all series so a mousemove at column i lines
        // up across every line.
        $series = [];
        $stepX = $n > 1 ? $plotWidth / ($n - 1) : 0;

        foreach ($eventTypes as $et) {
            $counts = $seriesCounts[$et] ?? [];
            $points = [];
            $i = 0;
            foreach ($bucketMeta as $key => $_) {
                $count = $counts[$key] ?? 0;
                $x = $padLeft + $i * $stepX;
                $y = $baseY - ($plotHeight * $count / $effectiveMax);
                $points[] = ['x' => $x, 'y' => $y, 'count' => $count];
                $i++;
            }

            $rawPoints = array_map(fn($p) => [$p['x'], $p['y']], $points);
            // Clamp control points inside the plot so a Catmull-Rom
            // overshoot can't dip below the x-axis (= zero count).
            [$linePath, $areaPath] = $this->buildSmoothPath($rawPoints, $baseY, $padLeft, $padY);

            $series[] = [
                'key' => $et,
                'color' => self::MONITOR_EVENT_COLORS[$et] ?? '#2f80ed',
                'linePath' => $linePath,
                'areaPath' => $areaPath,
                'points' => $points,
            ];
        }

        // Stat cards: totals per event type within the same window,
        // respecting context and client filters (but not the event
        // filter — the cards exist to compare event types). Same
        // all-selected-means-no-filter rule as the main query so
        // NULL-column rows aren't silently dropped.
        $statCard = function (string $type) use (
            $cutoffStr, $contexts, $clients, $allContexts, $allClients
        ): int {
            $q = (new \yii\db\Query())
                ->from('{{%user_activity_log}}')
                ->andWhere(['eventType' => $type])
                ->andWhere(['>=', 'dateCreated', $cutoffStr]);
            if (!$contexts || !$clients) {
                return 0;
            }
            if (count($contexts) < count($allContexts)) $q->andWhere(['context' => $contexts]);
            if (count($clients) < count($allClients)) $q->andWhere(['client' => $clients]);
            return (int)$q->count();
        };

        $stats = [
            'logins' => $statCard(ActivityLogService::EVENT_LOGIN),
            'logouts' => $statCard(ActivityLogService::EVENT_LOGOUT),
            'failed' => $statCard(ActivityLogService::EVENT_LOGIN_FAILED),
            'blocked' => $statCard(ActivityLogService::EVENT_LOGIN_BLOCKED),
        ];

        // Y-axis tick values. 5 evenly-spaced ticks from 0 to max,
        // rendered at 0/25/50/75/100 % of the plot height.
        $yTicks = [];
        for ($i = 4; $i >= 0; $i--) {
            $pct = $i / 4;
            $value = $i === 4 ? $effectiveMax : (int)floor($effectiveMax * $pct);
            $yTicks[] = [
                'value' => $value,
                'y' => $padY + $plotHeight * (1 - $pct),
            ];
        }

        return $this->renderTemplate('user-audit/monitor', [
            'buckets' => $bucketList,
            'maxCount' => $effectiveMax,
            'yTicks' => $yTicks,
            'useDaily' => $useDaily,
            'range' => $range,
            'stats' => $stats,
            'eventTypes' => $eventTypes,
            'contexts' => $contexts,
            'clients' => $clients,
            'eventColors' => self::MONITOR_EVENT_COLORS,
            'contextColors' => self::MONITOR_CONTEXT_COLORS,
            'clientColors' => self::MONITOR_CLIENT_COLORS,
            'chartWidth' => $chartWidth,
            'chartHeight' => $chartHeight,
            'chartPadLeft' => $padLeft,
            'chartPadRight' => $padRight,
            'chartPadY' => $padY,
            'chartSeries' => $series,
        ]);
    }

    /**
     * User trace: full activity log + headline stats + week×hour
     * heatmap for a single user. Reachable from the "Benutzer" column
     * link on the Logs page.
     *
     * userId comes via the URL rule (\d+) so it's always positive.
     * If the audit table has zero rows for that user we 404 — there's
     * nothing to show. The Craft user record may be missing (FK is
     * SET NULL for deleted users); in that case we fall back to the
     * email/groups stored on the latest audit row, which doubles as
     * a compliance feature: deleted users remain inspectable.
     */
    public function actionUser(int $userId): Response
    {
        if ($userId <= 0) {
            throw new \yii\web\NotFoundHttpException();
        }

        // Fetch latest audit row to ensure the user has any activity
        // and to source fallback identity (email/groups) when the
        // craft user has been deleted.
        $latestRow = UserActivityLog::find()
            ->where(['userId' => $userId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        if (!$latestRow) {
            throw new \yii\web\NotFoundHttpException(
                Craft::t('user-audit', 'No activity recorded for this user.')
            );
        }

        $craftUser = Craft::$app->getUsers()->getUserById($userId);

        // ---- Log table (sortable, paginated) -------------------------
        $request = Craft::$app->getRequest();
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $perPage = 50;
        $sort = (string)$request->getQueryParam('sort', 'dateCreated');
        $dir = strtolower((string)$request->getQueryParam('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (!in_array($sort, self::SORTABLE_COLUMNS, true)) {
            $sort = 'dateCreated';
        }
        $sortDir = $dir === 'asc' ? SORT_ASC : SORT_DESC;
        $orderBy = $sort === 'dateCreated'
            ? ['dateCreated' => $sortDir]
            : [$sort => $sortDir, 'dateCreated' => SORT_DESC];

        $logQuery = UserActivityLog::find()
            ->where(['userId' => $userId])
            ->orderBy($orderBy);

        $total = (int)$logQuery->count();
        $rows = $logQuery
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        // ---- Headline stats ------------------------------------------
        // Single roll-up query with conditional aggregation: replaces
        // the 6 separate COUNT(*) round-trips that v1.3.1 fired off.
        // CASE WHEN ... THEN 1 ELSE 0 with SUM is portable across
        // MySQL/MariaDB/Postgres; using COUNT() with the boolean would
        // not be (Postgres returns 0/1 ints, MySQL doesn't).
        $db = Craft::$app->getDb();
        $cutoff24h = (new \DateTime('-24 hours'))->format('Y-m-d H:i:s');
        $cutoff7d = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');

        $statsRow = $db->createCommand(
            'SELECT
                SUM(CASE WHEN [[eventType]] = :loginEvt   AND [[dateCreated]] >= :h24 THEN 1 ELSE 0 END) AS logins24h,
                SUM(CASE WHEN [[eventType]] = :loginEvt   AND [[dateCreated]] >= :d7  THEN 1 ELSE 0 END) AS logins7d,
                SUM(CASE WHEN [[eventType]] = :failedEvt  AND [[dateCreated]] >= :h24 THEN 1 ELSE 0 END) AS failed24h,
                SUM(CASE WHEN [[eventType]] = :failedEvt                              THEN 1 ELSE 0 END) AS failedTotal,
                SUM(CASE WHEN [[eventType]] = :blockedEvt                             THEN 1 ELSE 0 END) AS blockedTotal
             FROM {{%user_activity_log}}
             WHERE [[userId]] = :u',
            [
                ':u' => $userId,
                ':loginEvt' => ActivityLogService::EVENT_LOGIN,
                ':failedEvt' => ActivityLogService::EVENT_LOGIN_FAILED,
                ':blockedEvt' => ActivityLogService::EVENT_LOGIN_BLOCKED,
                ':h24' => $cutoff24h,
                ':d7' => $cutoff7d,
            ]
        )->queryOne();

        $stats = [
            'total' => $total,
            'logins24h' => (int)($statsRow['logins24h'] ?? 0),
            'logins7d' => (int)($statsRow['logins7d'] ?? 0),
            'failed24h' => (int)($statsRow['failed24h'] ?? 0),
            'failedTotal' => (int)($statsRow['failedTotal'] ?? 0),
            'blockedTotal' => (int)($statsRow['blockedTotal'] ?? 0),
        ];

        $lastLogin = UserActivityLog::find()
            ->where(['userId' => $userId, 'eventType' => ActivityLogService::EVENT_LOGIN])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        $lastFailed = UserActivityLog::find()
            ->where(['userId' => $userId, 'eventType' => ActivityLogService::EVENT_LOGIN_FAILED])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        // ---- Top-N aggregates over the last 90 days -----------------
        $cutoff90 = (new \DateTime('-90 days'))->format('Y-m-d H:i:s');
        $topField = static function (string $col) use ($db, $userId, $cutoff90): array {
            $rows = $db->createCommand(
                "SELECT [[$col]] AS v, COUNT(*) AS c
                 FROM {{%user_activity_log}}
                 WHERE [[userId]] = :u AND [[$col]] IS NOT NULL
                   AND [[dateCreated]] >= :since
                 GROUP BY [[$col]]
                 ORDER BY c DESC
                 LIMIT 5",
                [':u' => $userId, ':since' => $cutoff90]
            )->queryAll();
            return array_map(fn($r) => ['value' => $r['v'], 'count' => (int)$r['c']], $rows);
        };

        $topIps = $topField('ipAddress');
        $topDevices = $topField('deviceType');
        $topBrowsers = $topField('browserName');

        // ---- Heatmap: 7 (Mon-Sun) × 24 (hours) over 90 days ----------
        // Bucket at the DB level via GROUP BY (weekday, hour) so the
        // result set is bounded to ≤168 rows regardless of how active
        // the user is. v1.3.1 pulled every login row of the last 90
        // days into PHP and counted them in a foreach — for power
        // users that turned the page into a multi-second load.
        //
        // Driver-specific expressions because there is no single
        // standard for "weekday number" across MySQL/MariaDB/Postgres:
        //   - MySQL/MariaDB: WEEKDAY() returns 0=Mon..6=Sun. We add 1
        //     to align with PHP's ISO-N (1=Mon..7=Sun).
        //   - Postgres:      EXTRACT(ISODOW) returns 1=Mon..7=Sun.
        // Only login events count — failed/blocked/logout would
        // distort the "when does this user actually use the system?"
        // picture the heatmap is meant to answer.
        $heatmap = [];
        for ($d = 1; $d <= 7; $d++) {
            for ($h = 0; $h <= 23; $h++) {
                $heatmap[$d][$h] = 0;
            }
        }
        $heatmapMax = 0;
        $heatmapTotal = 0;

        $isPg = $db->getDriverName() === 'pgsql';
        if ($isPg) {
            $dowExpr = 'EXTRACT(ISODOW FROM [[dateCreated]])::int';
            $hourExpr = 'EXTRACT(HOUR FROM [[dateCreated]])::int';
        } else {
            $dowExpr = '(WEEKDAY([[dateCreated]]) + 1)';
            $hourExpr = 'HOUR([[dateCreated]])';
        }

        $heatmapBuckets = (new \yii\db\Query())
            ->select([
                'dow' => new \yii\db\Expression($dowExpr),
                'hr' => new \yii\db\Expression($hourExpr),
                'c' => new \yii\db\Expression('COUNT(*)'),
            ])
            ->from('{{%user_activity_log}}')
            ->where(['userId' => $userId, 'eventType' => ActivityLogService::EVENT_LOGIN])
            ->andWhere(['>=', 'dateCreated', $cutoff90])
            ->groupBy([new \yii\db\Expression($dowExpr), new \yii\db\Expression($hourExpr)])
            ->all();

        foreach ($heatmapBuckets as $b) {
            $dow = (int)$b['dow'];
            $hour = (int)$b['hr'];
            $count = (int)$b['c'];
            // Defensive: if a future driver returns dow=0 (Sun-based)
            // we'd land outside the initialized 1..7 grid. Skip it
            // rather than silently producing a malformed heatmap.
            if ($dow < 1 || $dow > 7 || $hour < 0 || $hour > 23) {
                continue;
            }
            $heatmap[$dow][$hour] = $count;
            $heatmapTotal += $count;
            if ($count > $heatmapMax) {
                $heatmapMax = $count;
            }
        }

        return $this->renderTemplate('user-audit/user', [
            'targetUserId' => $userId,
            'targetUser' => $craftUser,
            'fallbackEmail' => $latestRow->email,
            'fallbackGroups' => $latestRow->userGroups,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int)ceil($total / $perPage)),
            'sort' => $sort,
            'dir' => $dir,
            'stats' => $stats,
            'lastLogin' => $lastLogin,
            'lastFailed' => $lastFailed,
            'topIps' => $topIps,
            'topDevices' => $topDevices,
            'topBrowsers' => $topBrowsers,
            'heatmap' => $heatmap,
            'heatmapMax' => $heatmapMax,
            'heatmapTotal' => $heatmapTotal,
        ]);
    }

    /**
     * Builds a smooth SVG path through the given XY points using a
     * Catmull-Rom → cubic-Bezier conversion with moderate tension.
     * Returns [linePath, areaPath] — the area path closes down to
     * $baseY so it can be filled as the shaded region under the curve.
     *
     * Empty input returns empty strings; a single point returns a
     * degenerate line (M ... L ...) so consumers don't have to
     * special-case it.
     *
     * @param array<int,array{0:float,1:float}> $points
     * @return array{0:string,1:string}
     */
    private function buildSmoothPath(array $points, float $baseY, float $padX, float $padY): array
    {
        $n = count($points);
        if ($n === 0) {
            return ['', ''];
        }
        $fmt = static fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        // Bezier control-point clamp: SVG Y grows downward, so $padY is
        // the top (= max count) and $baseY the bottom (= 0). Keeping CP
        // Y values inside [padY, baseY] prevents the Catmull-Rom curve
        // from overshooting below the x-axis or above the chart area.
        $clampY = static fn(float $y): float => max($padY, min($baseY, $y));

        $line = 'M ' . $fmt($points[0][0]) . ',' . $fmt($points[0][1]);
        if ($n === 1) {
            $line .= ' L ' . $fmt($points[0][0] + 0.01) . ',' . $fmt($points[0][1]);
        } else {
            $tension = 0.2;
            for ($i = 0; $i < $n - 1; $i++) {
                $p0 = $points[max(0, $i - 1)];
                $p1 = $points[$i];
                $p2 = $points[$i + 1];
                $p3 = $points[min($n - 1, $i + 2)];
                $c1x = $p1[0] + ($p2[0] - $p0[0]) * $tension;
                $c1y = $clampY($p1[1] + ($p2[1] - $p0[1]) * $tension);
                $c2x = $p2[0] - ($p3[0] - $p1[0]) * $tension;
                $c2y = $clampY($p2[1] - ($p3[1] - $p1[1]) * $tension);
                $line .= ' C ' . $fmt($c1x) . ',' . $fmt($c1y)
                       . ' ' . $fmt($c2x) . ',' . $fmt($c2y)
                       . ' ' . $fmt($p2[0]) . ',' . $fmt($p2[1]);
            }
        }

        $last = $points[$n - 1];
        $area = 'M ' . $fmt($padX) . ',' . $fmt($baseY)
              . ' L ' . $fmt($points[0][0]) . ',' . $fmt($points[0][1])
              . ' ' . substr($line, strlen('M ' . $fmt($points[0][0]) . ',' . $fmt($points[0][1])))
              . ' L ' . $fmt($last[0]) . ',' . $fmt($baseY) . ' Z';

        return [$line, $area];
    }

    /**
     * Normalizes a multi-select query param into an array of accepted
     * string values. Accepts arrays (?foo[]=a&foo[]=b), a single scalar
     * (?foo=a) or empty/missing. Anything outside $allowed is dropped —
     * the URL is user-provided so we never let arbitrary values reach
     * an SQL IN clause.
     *
     * @param string[] $allowed
     * @return string[]
     */
    private function multiParam(\yii\web\Request $request, string $name, array $allowed): array
    {
        $raw = $request->getQueryParam($name);
        if ($raw === null || $raw === '') return [];
        $values = is_array($raw) ? $raw : [(string)$raw];
        $clean = [];
        foreach ($values as $v) {
            $v = (string)$v;
            if ($v !== '' && in_array($v, $allowed, true) && !in_array($v, $clean, true)) {
                $clean[] = $v;
            }
        }
        return $clean;
    }

    /**
     * Returns the last N audit entries of the currently logged-in
     * user as JSON. Consumed by the PWA page /konto so the user can
     * review their own login history (trust feature: "where was I
     * last active").
     *
     * Restricted to the user's own userId, no permission check
     * needed: every user may see their own history.
     */
    public function actionMyRecent(): Response
    {
        $limit = min(50, max(1, (int)Craft::$app->getRequest()->getQueryParam('limit', 20)));
        $userId = (int)Craft::$app->getUser()->getId();

        $rows = UserActivityLog::find()
            ->where(['userId' => $userId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();

        $payload = array_map(fn(UserActivityLog $r) => [
            'dateCreated' => $r->dateCreated,
            'eventType' => $r->eventType,
            'context' => $r->context,
            'ipAddress' => $r->ipAddress,
            'deviceType' => $r->deviceType,
            'osName' => $r->osName,
            'osVersion' => $r->osVersion,
            'browserName' => $r->browserName,
            'browserVersion' => $r->browserVersion,
            'failureReason' => $r->failureReason,
        ], $rows);

        return $this->asJson(['entries' => $payload]);
    }

    /**
     * Fired by the PWA as soon as it detects that the server
     * responds with isGuest=true while the PWA was locally still
     * considered logged in. Purely informational — writes a
     * session_expired entry with (optional) userId and email from
     * the request body.
     *
     * Accepts anonymous and no-CSRF calls because session cookie
     * and CSRF token are usually already invalid at this point.
     */
    public function actionSessionExpired(): Response
    {
        $this->requirePostRequest();

        // Session-expired is by definition a frontend event — if
        // the user disabled FE events, the endpoint just stays
        // silent (no error).
        /** @var \pixelwerft\useraudit\models\Settings $settings */
        $settings = UserAudit::getInstance()->getSettings();
        if (!$settings->recordFrontendEvents) {
            return $this->asJson(['ok' => true, 'skipped' => true]);
        }

        $request = Craft::$app->getRequest();
        $userId = (int)$request->getBodyParam('userId', 0);
        $email = (string)$request->getBodyParam('email', '');

        UserAudit::getInstance()->activityLog->log(
            ActivityLogService::EVENT_SESSION_EXPIRED,
            $userId > 0 ? $userId : null,
            [
                'email' => $email !== '' ? $email : null,
                'context' => ActivityLogService::CONTEXT_FE,
            ]
        );

        return $this->asJson(['ok' => true]);
    }

    /**
     * CSV export with the same filters as the index view. Streams
     * rows one by one from the query iterator to STDOUT so even
     * large exports complete without a memory peak.
     */
    public function actionExport(): Response
    {
        $request = Craft::$app->getRequest();
        $eventType = (string)$request->getQueryParam('event', '');
        $context = (string)$request->getQueryParam('context', '');
        $client = (string)$request->getQueryParam('client', '');
        $q = trim((string)$request->getQueryParam('q', ''));
        $effectiveQ = mb_strlen($q) >= 2 ? $q : '';

        $query = $this->buildQuery($eventType, $effectiveQ, $context, $client);

        // v2.0: pick up elements.dateDeleted for the new `deleted_at`
        // CSV column. Soft-deleted rows stay in the export so that
        // compliance audits see the rotation timestamp; hard-deleted
        // rows are gone from both tables and naturally absent.
        // Same `[[…]]` / `{{%…}}` non-nesting rule as actionIndex —
        // resolve the table name explicitly first.
        $rawTable = Craft::$app->getDb()->getSchema()->getRawTableName('{{%user_activity_log}}');
        $query->leftJoin(
            '{{%elements}} elements',
            "[[elements.id]] = [[{$rawTable}.elementId]]"
        );
        $query->addSelect(['elementDateDeleted' => '[[elements.dateDeleted]]']);

        $filename = sprintf(
            'user-audit-%s.csv',
            (new \DateTime())->format('Ymd-His')
        );

        $response = Craft::$app->getResponse();
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="' . $filename . '"'
        );

        // With callable streams Yii2 expects a generator that yields
        // strings — a direct fwrite to php://output ends up in a
        // TypeError (null → foreach) which surfaces as
        // ERR_INVALID_RESPONSE in the browser.
        $toCsv = static function (array $fields): string {
            $h = fopen('php://memory', 'r+');
            fputcsv($h, $fields);
            rewind($h);
            $s = stream_get_contents($h);
            fclose($h);
            return $s;
        };

        $response->stream = function () use ($query, $toCsv) {
            // BOM so Excel detects UTF-8 (without it umlauts get mangled).
            yield "\xEF\xBB\xBF";
            yield $toCsv([
                'dateCreated', 'eventType', 'context', 'client', 'userId', 'email',
                'userGroups', 'ipAddress', 'deviceType', 'osName', 'osVersion',
                'browserName', 'browserVersion', 'failureReason', 'userAgent',
                // v2.0: timestamp of soft-delete (empty for live rows).
                // Hard-deleted rows are not exportable — they are
                // physically gone from both tables.
                'deleted_at',
            ]);
            foreach ($query->each(500) as $row) {
                /** @var UserActivityLog $row */
                // elementDateDeleted came in as an aliased column on
                // the underlying SELECT — Yii surfaces it on AR
                // instances as a public-readable property.
                $deletedAt = $row->elementDateDeleted ?? '';
                yield $toCsv([
                    $row->dateCreated,
                    $row->eventType,
                    $row->context,
                    $row->client,
                    $row->userId,
                    $row->email,
                    $row->userGroups,
                    $row->ipAddress,
                    $row->deviceType,
                    $row->osName,
                    $row->osVersion,
                    $row->browserName,
                    $row->browserVersion,
                    $row->failureReason,
                    $row->userAgent,
                    $deletedAt,
                ]);
            }
        };

        return $response;
    }

    /**
     * Sort columns the index view is allowed to order by. Anything
     * outside this allowlist is ignored — the caller is user-provided
     * so we must not let arbitrary strings reach ORDER BY.
     */
    private const SORTABLE_COLUMNS = [
        'dateCreated', 'eventType', 'context', 'client', 'userId', 'email',
        'userGroups', 'ipAddress', 'deviceType', 'osName', 'browserName',
        'failureReason',
    ];

    /**
     * Unified filter builder for index and export — identical filter
     * semantics between UI and CSV guarantee that "export what I see"
     * actually holds.
     */
    private function buildQuery(
        string $eventType,
        string $q,
        string $context = '',
        string $client = '',
        string $sort = 'dateCreated',
        string $dir = 'desc'
    ): \yii\db\ActiveQuery {
        if (!in_array($sort, self::SORTABLE_COLUMNS, true)) {
            $sort = 'dateCreated';
        }
        $sortDir = strtolower($dir) === 'asc' ? SORT_ASC : SORT_DESC;

        // Always fall back to dateCreated DESC as a secondary sort so
        // rows with identical values in the primary column stay in
        // chronological order (and pagination stays deterministic).
        $orderBy = $sort === 'dateCreated'
            ? ['dateCreated' => $sortDir]
            : [$sort => $sortDir, 'dateCreated' => SORT_DESC];

        $query = UserActivityLog::find()->orderBy($orderBy);

        if ($eventType !== '') {
            $query->andWhere(['eventType' => $eventType]);
        }
        if ($context !== '') {
            $query->andWhere(['context' => $context]);
        }
        if ($client !== '') {
            $query->andWhere(['client' => $client]);
        }
        if ($q !== '') {
            // LIKE across all "narrow" textual fields. userAgent is
            // deliberately NOT included — a TEXT column with %q% LIKE
            // is expensive and would become a bottleneck as the table
            // grows. The parsed browser/OS/device fields cover 95%
            // of UA searches; for the rest there's the CSV export.
            $query->andWhere([
                'or',
                ['like', 'email', $q],
                ['like', 'userGroups', $q],
                ['like', 'ipAddress', $q],
                ['like', 'browserName', $q],
                ['like', 'browserVersion', $q],
                ['like', 'osName', $q],
                ['like', 'osVersion', $q],
                ['like', 'deviceType', $q],
                ['like', 'failureReason', $q],
                ['like', 'eventType', $q],
                ['like', 'client', $q],
            ]);
        }

        return $query;
    }
}
