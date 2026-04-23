<?php

namespace pixelwerft\useraudit\controllers;

use Craft;
use craft\web\Controller;
use pixelwerft\useraudit\records\UserActivityLog;
use pixelwerft\useraudit\services\ActivityLogService;
use pixelwerft\useraudit\UserAudit;
use yii\web\ForbiddenHttpException;
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

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $perPage = 100;
        $eventType = (string)$request->getQueryParam('event', '');
        $context = (string)$request->getQueryParam('context', '');
        $client = (string)$request->getQueryParam('client', '');
        $q = trim((string)$request->getQueryParam('q', ''));
        $sort = (string)$request->getQueryParam('sort', 'dateCreated');
        $dir = strtolower((string)$request->getQueryParam('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Live filter: only apply the search string from 2 characters
        // onwards, so individual keystrokes don't immediately trigger
        // a full table scan. 0 chars = no filter, 1 char = ignored.
        $effectiveQ = mb_strlen($q) >= 2 ? $q : '';

        $query = $this->buildQuery($eventType, $effectiveQ, $context, $client, $sort, $dir);

        $total = (int)$query->count();
        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return $this->renderTemplate('user-audit/index', [
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
        // when nothing is selected — avoids a full-table scan with an
        // empty IN clause (different drivers handle that differently).
        if ($eventTypes && $contexts && $clients) {
            $query = (new \yii\db\Query())
                ->select(['dateCreated', 'eventType'])
                ->from('{{%user_activity_log}}')
                ->andWhere(['>=', 'dateCreated', $cutoffStr])
                ->andWhere(['eventType' => $eventTypes])
                ->andWhere(['context' => $contexts])
                ->andWhere(['client' => $clients]);

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
            [$linePath] = $this->buildSmoothPath($rawPoints, $baseY, $padLeft, $padY);

            $series[] = [
                'key' => $et,
                'color' => self::MONITOR_EVENT_COLORS[$et] ?? '#2f80ed',
                'linePath' => $linePath,
                'points' => $points,
            ];
        }

        // Stat cards: totals per event type within the same window,
        // respecting context and client filters (but not the event
        // filter — the cards exist to compare event types).
        $statCard = function (string $type) use ($cutoffStr, $contexts, $clients): int {
            $q = (new \yii\db\Query())
                ->from('{{%user_activity_log}}')
                ->andWhere(['eventType' => $type])
                ->andWhere(['>=', 'dateCreated', $cutoffStr]);
            if ($contexts) $q->andWhere(['context' => $contexts]);
            if ($clients) $q->andWhere(['client' => $clients]);
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
            ]);
            foreach ($query->each(500) as $row) {
                /** @var UserActivityLog $row */
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
