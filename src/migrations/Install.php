<?php

namespace pixelwerft\useraudit\migrations;

use craft\db\Migration;

/**
 * Initial install migration. Run by Craft during plugin/install and
 * creates the plugin's single table.
 *
 * userId is set up as a SET NULL FK to {{%users}}: audit entries
 * must remain readable even after the user is later deleted
 * (otherwise compliance-relevant history would be lost).
 *
 * Later schema changes belong in `m{YYMMDD}_*` migrations alongside
 * this file, NOT by editing it — Install.php only runs once per
 * installation.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema('{{%user_activity_log}}') !== null) {
            return true;
        }

        $this->createTable('{{%user_activity_log}}', [
            'id' => $this->primaryKey(),
            // v2.0+: every audit row is also a Craft element. The FK
            // is filled in by ActivityLogService::log() right before
            // the row is saved. For fresh installs the column starts
            // out NOT NULL — there is nothing to backfill.
            'elementId' => $this->integer()->notNull(),
            'userId' => $this->integer(),
            'email' => $this->string(255),
            'userGroups' => $this->string(512),
            'eventType' => $this->string(64)->notNull(),
            'context' => $this->string(16),
            'failureReason' => $this->string(255),
            'ipAddress' => $this->string(45),
            'userAgent' => $this->text(),
            'client' => $this->string(32),
            // v2.3.0: server-issued UUID that links login → logout
            // rows for a single browser session; enables
            // sessionDurationSeconds computation.
            'sessionId' => $this->string(36),
            'deviceType' => $this->string(32),
            'osName' => $this->string(64),
            'osVersion' => $this->string(32),
            'browserName' => $this->string(64),
            'browserVersion' => $this->string(32),
            'metadata' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%user_activity_log}}', 'userId');
        $this->createIndex(null, '{{%user_activity_log}}', 'eventType');
        $this->createIndex(null, '{{%user_activity_log}}', 'context');
        $this->createIndex(null, '{{%user_activity_log}}', 'client');
        $this->createIndex(null, '{{%user_activity_log}}', 'sessionId');
        $this->createIndex(null, '{{%user_activity_log}}', 'dateCreated');
        // Unique elementId — one element per audit row.
        $this->createIndex(null, '{{%user_activity_log}}', 'elementId', true);

        $this->addForeignKey(
            null,
            '{{%user_activity_log}}',
            'userId',
            '{{%users}}',
            'id',
            'SET NULL',
            null
        );

        // ON DELETE CASCADE: the explicit `purge/hard` console command
        // deletes the element; MySQL drops the audit row alongside.
        $this->addForeignKey(
            null,
            '{{%user_activity_log}}',
            'elementId',
            '{{%elements}}',
            'id',
            'CASCADE',
            null
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%user_activity_log}}');
        return true;
    }
}
