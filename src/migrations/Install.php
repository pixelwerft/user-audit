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
            'userId' => $this->integer(),
            'email' => $this->string(255),
            'userGroups' => $this->string(512),
            'eventType' => $this->string(64)->notNull(),
            'context' => $this->string(16),
            'failureReason' => $this->string(255),
            'ipAddress' => $this->string(45),
            'userAgent' => $this->text(),
            'client' => $this->string(32),
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
        $this->createIndex(null, '{{%user_activity_log}}', 'dateCreated');

        $this->addForeignKey(
            null,
            '{{%user_activity_log}}',
            'userId',
            '{{%users}}',
            'id',
            'SET NULL',
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
