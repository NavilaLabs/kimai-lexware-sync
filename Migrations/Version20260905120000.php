<?php

declare(strict_types=1);

namespace KimaiLexwareSyncBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260905120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the KimaiLexwareSync milestone two invoice tracking tables';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_ext_lexware_invoice')) {
            $table = $schema->createTable('kimai2_ext_lexware_invoice');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('lexware_id', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('voucher_number', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('voucher_date', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('raw_payload', 'text', ['notnull' => true]);
            $table->addColumn('related_order_confirmation_id', 'integer', ['notnull' => true]);
            $table->addColumn('status', 'string', ['notnull' => true, 'length' => 30]);
            $table->addColumn('creation_attempted_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('created_invoice_lexware_id', 'string', ['notnull' => false, 'length' => 100]);
            $table->addColumn('first_seen_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('last_synchronized_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('remote_updated_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('processed_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('processed_by_id', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['lexware_id']);
            $table->addForeignKeyConstraint('kimai2_ext_lexware_order_confirmation', ['related_order_confirmation_id'], ['id']);
            $table->addForeignKeyConstraint('kimai2_users', ['processed_by_id'], ['id'], ['onDelete' => 'SET NULL']);
        }

        if (!$schema->hasTable('kimai2_ext_lexware_invoice_timesheet')) {
            $table = $schema->createTable('kimai2_ext_lexware_invoice_timesheet');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('tracked_invoice_id', 'integer', ['notnull' => true]);
            $table->addColumn('timesheet_id', 'integer', ['notnull' => false]);
            $table->addColumn('timesheet_modified_at_snapshot', 'datetime_immutable', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addForeignKeyConstraint('kimai2_ext_lexware_invoice', ['tracked_invoice_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('kimai2_timesheet', ['timesheet_id'], ['id'], ['onDelete' => 'SET NULL']);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['kimai2_ext_lexware_invoice_timesheet', 'kimai2_ext_lexware_invoice'] as $tableName) {
            if ($schema->hasTable($tableName)) {
                $schema->dropTable($tableName);
            }
        }
    }
}
