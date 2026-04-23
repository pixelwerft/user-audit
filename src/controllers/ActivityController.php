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
     * Monitor: pure time-series dashboard. Renders a bucketed bar
     * chart of event activity for the selected window, filterable
     * by event type, context and client — the same dropdowns the
     * log list offers. Deliberately no log rows on this page; it's
     * meant to answer "how did activity look" at a glance, not
     * "what exactly happened".
     *
     * Buckets:
     *   windowHours ≤ 48 → one bar per hour
     *   windowHours  > 48 → one bar per day
     *
     * windowHours is capped at 168 (7 days) to keep the PHP-side
     * bucketing cheap; longer windows should use the CSV export.
     */
    public function actionMonitor(): Response
    {
        $request = Craft::$app->getRequest();
        $windowHours = max(1, min(168, (int)$request->getQueryParam('hours', 24)));
        $eventType = (string)$request->getQueryParam('event', '');
        $context = (string)$request->getQueryParam('context', '');
        $client = (string)$request->getQueryParam('client', '');

        $useDaily = $windowHours > 48;
        $bucketCount = $useDaily ? (int)ceil($windowHours / 24) : $windowHours;
        $bucketFormat = $useDaily ? 'Y-m-d' : 'Y-m-d H';

        // Cutoff rounded down to the start of the current bucket so
        // the most recent bar is fully populated, not half-empty.
        $anchor = new \DateTime();
        if ($useDaily) {
            $anchor->setTime(0, 0);
        } else {
            $anchor->setTime((int)$anchor->format('H'), 0);
        }

        $cutoff = (clone $anchor)->modify('-' . ($bucketCount - 1) . ($useDaily ? ' days' : ' hours'));
        $cutoffStr = $cutoff->format('Y-m-d H:i:s');

        // Initialize ordered bucket array so empty periods still show
        // up as zero bars (a flat gap would otherwise look like "nothing
        // happened" when really the chart just wasn't asked about it).
        $buckets = [];
        for ($i = $bucketCount - 1; $i >= 0; $i--) {
            $bucket = (clone $anchor)->modify('-' . $i . ($useDaily ? ' days' : ' hours'));
            $key = $bucket->format($bucketFormat);
            $buckets[$key] = [
                'key' => $key,
                'label' => $bucket->format($useDaily ? 'M j' : 'H:00'),
                'iso' => $bucket->format('c'),
                'count' => 0,
            ];
        }

        // Fetch only dateCreated; we bucket in PHP so this stays
        // portable across MySQL/MariaDB/Postgres (date-format SQL
        // varies by driver and isn't worth the abstraction cost here).
        $query = (new \yii\db\Query())
            ->select(['dateCreated'])
            ->from('{{%user_activity_log}}')
            ->andWhere(['>=', 'dateCreated', $cutoffStr]);
        if ($eventType !== '') $query->andWhere(['eventType' => $eventType]);
        if ($context !== '') $query->andWhere(['context' => $context]);
        if ($client !== '') $query->andWhere(['client' => $client]);

        foreach ($query->each(1000) as $row) {
            $key = (new \DateTime($row['dateCreated']))->format($bucketFormat);
            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
            }
        }

        $bucketList = array_values($buckets);
        $maxCount = 0;
        foreach ($bucketList as $b) {
            if ($b['count'] > $maxCount) $maxCount = $b['count'];
        }

        // SVG chart geometry — rendered at a fixed viewBox, CSS scales
        // it responsively. Padding keeps the curve off the axes so
        // stroke weight stays visible at the extremes.
        $chartWidth = 960;
        $chartHeight = 240;
        $padX = 24;
        $padY = 16;
        $plotWidth = $chartWidth - 2 * $padX;
        $plotHeight = $chartHeight - 2 * $padY;
        $baseY = $chartHeight - $padY;
        $n = count($bucketList);

        $points = [];
        if ($n > 0) {
            $stepX = $n > 1 ? $plotWidth / ($n - 1) : 0;
            foreach ($bucketList as $i => $b) {
                $x = $padX + $i * $stepX;
                $y = $baseY - ($plotHeight * $b['count'] / max(1, $maxCount));
                $points[] = [$x, $y];
            }
        }

        [$linePath, $areaPath] = $this->buildSmoothPath($points, $baseY, $padX);

        // Stat cards: totals per event type within the same window,
        // respecting context and client filters (but not the event
        // filter — the cards exist to compare event types).
        $statCard = function (string $type) use ($cutoffStr, $context, $client): int {
            $q = (new \yii\db\Query())
                ->from('{{%user_activity_log}}')
                ->andWhere(['eventType' => $type])
                ->andWhere(['>=', 'dateCreated', $cutoffStr]);
            if ($context !== '') $q->andWhere(['context' => $context]);
            if ($client !== '') $q->andWhere(['client' => $client]);
            return (int)$q->count();
        };

        $stats = [
            'logins' => $statCard(ActivityLogService::EVENT_LOGIN),
            'logouts' => $statCard(ActivityLogService::EVENT_LOGOUT),
            'failed' => $statCard(ActivityLogService::EVENT_LOGIN_FAILED),
            'blocked' => $statCard(ActivityLogService::EVENT_LOGIN_BLOCKED),
        ];

        return $this->renderTemplate('user-audit/monitor', [
            'buckets' => $bucketList,
            'maxCount' => max(1, $maxCount),
            'useDaily' => $useDaily,
            'windowHours' => $windowHours,
            'stats' => $stats,
            'eventType' => $eventType,
            'context' => $context,
            'client' => $client,
            'chartWidth' => $chartWidth,
            'chartHeight' => $chartHeight,
            'chartPadX' => $padX,
            'chartPadY' => $padY,
            'chartPoints' => $points,
            'chartLinePath' => $linePath,
            'chartAreaPath' => $areaPath,
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
    private function buildSmoothPath(array $points, float $baseY, float $padX): array
    {
        $n = count($points);
        if ($n === 0) {
            return ['', ''];
        }
        $fmt = static fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

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
                $c1y = $p1[1] + ($p2[1] - $p0[1]) * $tension;
                $c2x = $p2[0] - ($p3[0] - $p1[0]) * $tension;
                $c2y = $p2[1] - ($p3[1] - $p1[1]) * $tension;
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
