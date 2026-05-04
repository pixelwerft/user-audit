<?php

namespace pixelwerft\useraudit\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\StringHelper;
use pixelwerft\useraudit\elements\AuditLog;
use yii\db\Query;

/**
 * v2.0 — Convert {{%user_activity_log}} into Craft Elements.
 *
 * Adds an `elementId` FK on the audit table linking each row to a
 * matching {{%elements}} row. Existing rows are backfilled in 500-row
 * batches; `elementId` starts nullable and is tightened to NOT NULL
 * after backfill completes.
 *
 * Why a separate FK column instead of sharing the elements PK (the
 * usual Craft idiom): it keeps existing audit-row IDs stable through
 * the upgrade, so any external reference, log line, or backup
 * referencing an audit-row ID remains valid. The trade-off is a few
 * extra lines in AuditLogQuery to do the manual JOIN — worth it.
 *
 * Direct DB inserts (instead of $elements->saveElement()) bypass the
 * before/after-save events, the search index update, and the slideout
 * resolver. Backfilling 100k rows via saveElement would fire 100k×
 * worth of events; direct inserts cut that to two writes per row.
 *
 * The migration is idempotent on the schema side (column / FK / index
 * checks) and resumable on the backfill side (only rows where
 * `elementId IS NULL` are touched).
 */
class m260505_120000_audit_log_to_elements extends Migration
{
    private const TABLE = '{{%user_activity_log}}';
    private const BATCH_SIZE = 500;

    public function safeUp(): bool
    {
        $schema = $this->db->getTableSchema(self::TABLE);
        if ($schema === null) {
            // Plugin has not been installed via Install.php yet — nothing
            // to migrate. Install.php for fresh installs already adds
            // elementId in v2.0+.
            return true;
        }

        // 1. Add elementId column (nullable for backfill phase).
        if ($schema->getColumn('elementId') === null) {
            $this->addColumn(self::TABLE, 'elementId', $this->integer()->after('id'));
        }

        // 2. Add a unique index — one element per audit row, no
        //    duplicates. Created before the FK so MySQL doesn't have
        //    to scan to confirm uniqueness on FK creation.
        if (!$this->indexExists(self::TABLE, 'elementId', true)) {
            $this->createIndex(null, self::TABLE, 'elementId', true);
        }

        // 3. FK to elements with ON DELETE CASCADE so the explicit
        //    hard-purge console command can simply delete the element
        //    and let MySQL clean up the audit row.
        if (!$this->foreignKeyExists(self::TABLE, 'elementId')) {
            $this->addForeignKey(
                null,
                self::TABLE,
                'elementId',
                '{{%elements}}',
                'id',
                'CASCADE',
                null
            );
        }

        // 4. Backfill existing rows.
        $this->backfillElements();

        // 5. Tighten elementId → NOT NULL. By now every row has one.
        $remaining = (int)(new Query())
            ->from(self::TABLE)
            ->where(['elementId' => null])
            ->count('*', $this->db);

        if ($remaining > 0) {
            // Refuse to tighten the column if backfill left rows behind.
            // Better to fail loudly than to UPDATE NULL → 0 silently
            // and produce orphan audit rows pointing at a non-existent
            // element id.
            throw new \RuntimeException(
                "[user-audit] migration aborted: {$remaining} audit rows still have a NULL elementId after backfill. "
                . "Re-run the migration to retry."
            );
        }

        $this->alterColumn(self::TABLE, 'elementId', $this->integer()->notNull());

        return true;
    }

    public function safeDown(): bool
    {
        $schema = $this->db->getTableSchema(self::TABLE);
        if ($schema === null || $schema->getColumn('elementId') === null) {
            return true;
        }

        // Drop FK + unique index before the column itself — MySQL will
        // refuse to drop the column otherwise.
        if ($this->foreignKeyExists(self::TABLE, 'elementId')) {
            \craft\helpers\MigrationHelper::dropForeignKeyIfExists(self::TABLE, ['elementId'], $this);
        }

        if ($this->indexExists(self::TABLE, 'elementId', true)) {
            \craft\helpers\MigrationHelper::dropIndexIfExists(self::TABLE, ['elementId'], true, $this);
        }

        // Delete the element rows we created. elements_sites cascade
        // via their own FK on elements.id.
        $this->delete('{{%elements}}', ['type' => AuditLog::class]);

        $this->dropColumn(self::TABLE, 'elementId');

        return true;
    }

    /**
     * Backfills {{%elements}} (+ {{%elements_sites}}) rows for every
     * audit-log row that does not yet have an elementId, in
     * BATCH_SIZE-row chunks. Resumable: each batch re-queries for
     * unbackfilled rows, so an interrupted run picks up where it
     * stopped on the next migration retry.
     */
    private function backfillElements(): void
    {
        $primarySite = Craft::$app->getSites()->getPrimarySite();
        $primarySiteId = (int)$primarySite->id;

        $totalToDo = (int)(new Query())
            ->from(self::TABLE)
            ->where(['elementId' => null])
            ->count('*', $this->db);

        if ($totalToDo === 0) {
            $this->stdout("[user-audit] no audit rows to backfill.\n");
            return;
        }

        $this->stdout("[user-audit] backfilling {$totalToDo} audit rows into Craft elements (batch size " . self::BATCH_SIZE . ")...\n");

        $done = 0;
        while (true) {
            $rows = (new Query())
                ->select(['id', 'dateCreated', 'dateUpdated'])
                ->from(self::TABLE)
                ->where(['elementId' => null])
                ->orderBy(['id' => SORT_ASC])
                ->limit(self::BATCH_SIZE)
                ->all($this->db);

            if (empty($rows)) {
                break;
            }

            $transaction = $this->db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $now = $row['dateUpdated'];

                    $this->insert('{{%elements}}', [
                        'type'         => AuditLog::class,
                        'enabled'      => 1,
                        'archived'     => 0,
                        'fieldLayoutId' => null,
                        'canonicalId'  => null,
                        'draftId'      => null,
                        'revisionId'   => null,
                        'dateLastMerged' => null,
                        'dateDeleted'  => null,
                        'dateCreated'  => $row['dateCreated'],
                        'dateUpdated'  => $now,
                        'uid'          => StringHelper::UUID(),
                    ]);

                    $elementId = (int)$this->db->getLastInsertID();

                    $this->insert('{{%elements_sites}}', [
                        'elementId'   => $elementId,
                        'siteId'      => $primarySiteId,
                        'title'       => null,
                        'slug'        => null,
                        'uri'         => null,
                        'enabled'     => 1,
                        'dateCreated' => $row['dateCreated'],
                        'dateUpdated' => $now,
                        'uid'         => StringHelper::UUID(),
                    ]);

                    $this->update(
                        self::TABLE,
                        ['elementId' => $elementId],
                        ['id' => (int)$row['id']]
                    );
                }

                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            $done += count($rows);
            $this->stdout("[user-audit]   progress: {$done} / {$totalToDo}\n");
        }

        $this->stdout("[user-audit] backfill complete.\n");
    }

    // ------------------------------------------------------------------
    // Schema introspection helpers
    // ------------------------------------------------------------------

    private function indexExists(string $table, string $column, bool $unique): bool
    {
        $rawTableName = $this->db->getSchema()->getRawTableName($table);
        foreach ($this->db->getSchema()->findUniqueIndexes($this->db->getTableSchema($table)) as $cols) {
            if (count($cols) === 1 && $cols[0] === $column) {
                return true;
            }
        }
        // Fallback for non-unique check via raw SHOW INDEX — unique
        // index lookup above covers our v2.0 needs (we only create a
        // unique index on elementId).
        if (!$unique) {
            $indexes = $this->db->createCommand("SHOW INDEX FROM {$rawTableName}")->queryAll();
            foreach ($indexes as $idx) {
                if (($idx['Column_name'] ?? null) === $column) {
                    return true;
                }
            }
        }
        return false;
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        $schema = $this->db->getTableSchema($table);
        if ($schema === null) {
            return false;
        }
        foreach ($schema->foreignKeys as $fk) {
            // Yii layout: [<refTable>, <selfCol> => <refCol>, ...]
            unset($fk[0]);
            if (array_key_exists($column, $fk)) {
                return true;
            }
        }
        return false;
    }
}
