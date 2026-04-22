<?php

namespace pixelwerft\useraudit\migrations;

use craft\db\Migration;

/**
 * Adds the client and userGroups columns to {{%user_activity_log}}.
 *
 * client: 'pwa' (installed PWA or browser tab with the
 *         X-Reest-Client header),
 *         'browser' (regular browser login without the PWA header),
 *         NULL (CP login, console event or custom event without
 *         client info).
 *
 * userGroups: Comma-separated list of user group handles at login
 *             time. Snapshot — deliberately not pulled live from
 *             {{%users}}, so it remains possible to trace which
 *             groups the user was in at the time of the login
 *             (groups can change).
 *
 * Deliberately NO backfill — neither client nor groups of a past
 * login can be cleanly reconstructed after the fact.
 */
class m260422_120000_add_client_column extends Migration
{
    public function safeUp(): bool
    {
        $schema = $this->db->getTableSchema('{{%user_activity_log}}');
        if ($schema === null) {
            return true;
        }

        if ($schema->getColumn('client') === null) {
            $this->addColumn(
                '{{%user_activity_log}}',
                'client',
                $this->string(32)->after('context')
            );
            $this->createIndex(null, '{{%user_activity_log}}', 'client');
        }

        // Reload after the addColumn — otherwise $schema doesn't see
        // the new client column. The after ordering is irrelevant for
        // the second addColumn, but the explicit reload doesn't hurt.
        $schema = $this->db->getTableSchema('{{%user_activity_log}}', true);

        if ($schema !== null && $schema->getColumn('userGroups') === null) {
            $this->addColumn(
                '{{%user_activity_log}}',
                'userGroups',
                $this->string(512)->after('email')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $schema = $this->db->getTableSchema('{{%user_activity_log}}');
        if ($schema === null) {
            return true;
        }
        if ($schema->getColumn('userGroups') !== null) {
            $this->dropColumn('{{%user_activity_log}}', 'userGroups');
        }
        if ($schema->getColumn('client') !== null) {
            $this->dropColumn('{{%user_activity_log}}', 'client');
        }
        return true;
    }
}
