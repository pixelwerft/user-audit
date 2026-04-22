<?php

namespace pixelwerft\useraudit\controllers;

use Craft;
use craft\web\Controller;
use pixelwerft\useraudit\records\UserActivityLog;
use pixelwerft\useraudit\services\ActivityLogService;
use pixelwerft\useraudit\UserAudit;
use yii\web\Response;

/**
 * CP controller for the audit viewer UI.
 *
 * All actions require user-audit-view. The plugin owner can grant
 * the permission per admin user via Craft's permissions.
 */
class ActivityController extends Controller
{
    // session-expired: anonymous (the PWA can no longer be
    // authenticated when its session expires). my-recent: logged-in
    // user, no user-audit-view permission required (users may view
    // their own activity). Everything else: user-audit-view required.
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

        // Permission gate only for the admin actions (index, export).
        // session-expired and my-recent are user endpoints:
        //   - session-expired: anonymous (user just logged out)
        //   - my-recent: logged-in user views their own history
        if (!in_array($action->id, ['session-expired', 'my-recent'], true)) {
            $this->requirePermission('user-audit-view');
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

        // Live filter: only apply the search string from 2 characters
        // onwards, so individual keystrokes don't immediately trigger
        // a full table scan. 0 chars = no filter, 1 char = ignored.
        $effectiveQ = mb_strlen($q) >= 2 ? $q : '';

        $query = $this->buildQuery($eventType, $effectiveQ, $context, $client);

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
        ]);
    }

    /**
     * "Who is online right now?" — For each user within the last N
     * hours show the latest login that does NOT yet have a matching
     * logout after it.
     *
     * Heuristic (not real session tracking), but good enough for the
     * daily "who is active, from where, on what device?" glance.
     * Real sessions (Craft's cookie table) are complementary, but
     * they lack the parsed UA fields.
     */
    public function actionActive(): Response
    {
        $windowHours = max(1, (int)Craft::$app->getRequest()->getQueryParam('hours', 24));
        $cutoff = (new \DateTime("-{$windowHours} hours"))->format('Y-m-d H:i:s');

        $db = Craft::$app->getDb();

        // Per user, fetch the most recent login entry that has not
        // yet been followed by a logout. NOT EXISTS on the logout
        // row is cheaper than LEFT JOIN + IS NULL because the engine
        // can short-circuit early.
        $rows = $db->createCommand(
            'SELECT l.*
             FROM {{%user_activity_log}} l
             WHERE l.[[eventType]] = :tLogin
               AND l.[[dateCreated]] >= :since
               AND l.[[userId]] IS NOT NULL
               AND NOT EXISTS (
                 SELECT 1 FROM {{%user_activity_log}} o
                 WHERE o.[[userId]] = l.[[userId]]
                   AND o.[[eventType]] IN (:tLogout, :tExpired)
                   AND o.[[dateCreated]] > l.[[dateCreated]]
               )
               AND l.[[id]] = (
                 SELECT MAX(l2.[[id]]) FROM {{%user_activity_log}} l2
                 WHERE l2.[[userId]] = l.[[userId]]
                   AND l2.[[eventType]] = :tLogin
                   AND l2.[[dateCreated]] >= :since
               )
             ORDER BY l.[[dateCreated]] DESC',
            [
                ':tLogin' => ActivityLogService::EVENT_LOGIN,
                ':tLogout' => ActivityLogService::EVENT_LOGOUT,
                ':tExpired' => ActivityLogService::EVENT_SESSION_EXPIRED,
                ':since' => $cutoff,
            ]
        )->queryAll();

        return $this->renderTemplate('user-audit/active', [
            'rows' => $rows,
            'windowHours' => $windowHours,
        ]);
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
     * Unified filter builder for index and export — identical filter
     * semantics between UI and CSV guarantee that "export what I see"
     * actually holds.
     */
    private function buildQuery(
        string $eventType,
        string $q,
        string $context = '',
        string $client = ''
    ): \yii\db\ActiveQuery {
        $query = UserActivityLog::find()->orderBy(['dateCreated' => SORT_DESC]);

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
