<?php

namespace pixelwerft\useraudit\migrations;

use craft\db\Migration;

/**
 * Adds the context column to {{%user_activity_log}}.
 *
 * Values: 'cp' (CP backend login/activity), 'fe' (frontend/PWA),
 *         NULL (custom events or console).
 *
 * Existing rows stay NULL — we don't backfill because the request
 * context is no longer known after the request.
 */
class m260422_100000_add_context_column extends Migration
{
    public function safeUp(): bool
    {
        $schema = $this->db->getTableSchema('{{%user_activity_log}}');
        if ($schema !== null && $schema->getColumn('context') === null) {
            $this->addColumn(
                '{{%user_activity_log}}',
                'context',
                $this->string(16)->after('eventType')
            );
            $this->createIndex(null, '{{%user_activity_log}}', 'context');
        }
        return true;
    }

    public function safeDown(): bool
    {
        $schema = $this->db->getTableSchema('{{%user_activity_log}}');
        if ($schema !== null && $schema->getColumn('context') !== null) {
            $this->dropColumn('{{%user_activity_log}}', 'context');
        }
        return true;
    }
}
