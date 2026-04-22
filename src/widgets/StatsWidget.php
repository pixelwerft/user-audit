<?php

namespace pixelwerft\useraudit\widgets;

use Craft;
use craft\base\Widget;

/**
 * Dashboard widget with the key audit figures for the last 24 hours:
 * successful logins, failed logins, top IPs by fail count.
 *
 * The goal is deliberately a brief security glance ("are there
 * unusually many failed logins today?"), not a full analysis — for
 * that there is the detail page at /admin/user-audit.
 */
class StatsWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('user-audit', 'User Audit — 24h Stats');
    }

    public static function icon(): ?string
    {
        return '@pixelwerft/useraudit/icon-mask.svg';
    }

    public function getTitle(): ?string
    {
        return Craft::t('user-audit', 'User Audit');
    }

    public function getBodyHtml(): ?string
    {
        $db = Craft::$app->getDb();
        $since = (new \DateTime('-24 hours'))->format('Y-m-d H:i:s');

        $loginCount = (int)$db->createCommand(
            'SELECT COUNT(*) FROM {{%user_activity_log}}
             WHERE [[eventType]] = :t AND [[dateCreated]] >= :since',
            [':t' => 'login', ':since' => $since]
        )->queryScalar();

        $logoutCount = (int)$db->createCommand(
            'SELECT COUNT(*) FROM {{%user_activity_log}}
             WHERE [[eventType]] = :t AND [[dateCreated]] >= :since',
            [':t' => 'logout', ':since' => $since]
        )->queryScalar();

        $failedCount = (int)$db->createCommand(
            'SELECT COUNT(*) FROM {{%user_activity_log}}
             WHERE [[eventType]] = :t AND [[dateCreated]] >= :since',
            [':t' => 'login_failed', ':since' => $since]
        )->queryScalar();

        // Top 5 IPs by fail count (only if there were any fails —
        // otherwise the list is empty anyway).
        $topFailIps = $db->createCommand(
            'SELECT [[ipAddress]] AS ip, COUNT(*) AS c
             FROM {{%user_activity_log}}
             WHERE [[eventType]] = :t AND [[dateCreated]] >= :since
               AND [[ipAddress]] IS NOT NULL
             GROUP BY [[ipAddress]]
             ORDER BY c DESC
             LIMIT 5',
            [':t' => 'login_failed', ':since' => $since]
        )->queryAll();

        return Craft::$app->getView()->renderTemplate(
            'user-audit/_widgets/stats',
            [
                'loginCount' => $loginCount,
                'logoutCount' => $logoutCount,
                'failedCount' => $failedCount,
                'topFailIps' => $topFailIps,
            ]
        );
    }
}
