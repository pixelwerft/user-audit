<?php

namespace pixelwerft\useraudit\variables;

use Craft;
use pixelwerft\useraudit\records\UserActivityLog;
use pixelwerft\useraudit\services\ActivityLogService;

/**
 * v2.3.0: the `craft.userAudit` Twig variable.
 *
 * A deliberately tiny, read-only surface for front-end templates: it
 * only ever exposes the *current* user's own history, so there is no
 * permission gate to get wrong — a guest gets an empty list. Used by
 * the self-service snippet so a customer site can drop in
 * `{% include 'user-audit/_widgets/my_activity_snippet' %}` with no
 * controller wiring of their own.
 */
class UserAuditVariable
{
    /**
     * Returns the current user's most recent successful logins as
     * plain arrays (never records), newest first. Empty for guests.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recentLogins(int $limit = 5): array
    {
        $userId = (int)Craft::$app->getUser()->getId();
        if ($userId <= 0) {
            return [];
        }

        $rows = UserActivityLog::find()
            ->where([
                'userId' => $userId,
                'eventType' => ActivityLogService::EVENT_LOGIN,
            ])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(max(1, min(50, $limit)))
            ->all();

        return array_map(static function (UserActivityLog $r): array {
            $meta = $r->metadata ? json_decode($r->metadata, true) : null;
            return [
                'dateCreated' => $r->dateCreated,
                'ipAddress' => $r->ipAddress,
                'deviceType' => $r->deviceType,
                'osName' => $r->osName,
                'osVersion' => $r->osVersion,
                'browserName' => $r->browserName,
                'browserVersion' => $r->browserVersion,
                'riskScore' => is_array($meta) ? (int)($meta['riskScore'] ?? 0) : 0,
            ];
        }, $rows);
    }

    /**
     * True when any of the given recent-login entries carries a risk
     * score at or above $threshold — lets the snippet show a single
     * "review your recent activity" nudge without the template having
     * to loop twice.
     *
     * @param array<int,array<string,mixed>> $entries
     */
    public function hasElevatedRisk(array $entries, int $threshold = 3): bool
    {
        foreach ($entries as $e) {
            if ((int)($e['riskScore'] ?? 0) >= $threshold) {
                return true;
            }
        }
        return false;
    }
}
