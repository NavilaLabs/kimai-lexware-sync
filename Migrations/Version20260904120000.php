<?php

declare(strict_types=1);

namespace KimaiLexwareSyncBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the KimaiLexwareSync tracking tables';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_ext_lexware_order_confirmation')) {
            $table = $schema->createTable('kimai2_ext_lexware_order_confirmation');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('lexware_id', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('voucher_number', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('title', 'string', ['notnull' => true, 'length' => 255]);
            $table->addColumn('voucher_date', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('lexware_contact_id', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('raw_payload', 'text', ['notnull' => true]);
            $table->addColumn('status', 'string', ['notnull' => true, 'length' => 30]);
            $table->addColumn('changed_after_conversion', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('project_id', 'integer', ['notnull' => false]);
            $table->addColumn('customer_id', 'integer', ['notnull' => false]);
            $table->addColumn('first_seen_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('last_synchronized_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('processed_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('processed_by_id', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['lexware_id']);
            $table->addForeignKeyConstraint('kimai2_projects', ['project_id'], ['id'], ['onDelete' => 'SET NULL']);
            $table->addForeignKeyConstraint('kimai2_customers', ['customer_id'], ['id'], ['onDelete' => 'SET NULL']);
            $table->addForeignKeyConstraint('kimai2_users', ['processed_by_id'], ['id'], ['onDelete' => 'SET NULL']);
        }

        if (!$schema->hasTable('kimai2_ext_lexware_order_confirmation_line')) {
            $table = $schema->createTable('kimai2_ext_lexware_order_confirmation_line');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('order_confirmation_id', 'integer', ['notnull' => true]);
            $table->addColumn('position', 'integer', ['notnull' => true]);
            $table->addColumn('type', 'string', ['notnull' => true, 'length' => 50]);
            $table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', 'text', ['notnull' => false]);
            $table->addColumn('matched', 'boolean', ['notnull' => true]);
            $table->addColumn('activity_id', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addForeignKeyConstraint('kimai2_ext_lexware_order_confirmation', ['order_confirmation_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('kimai2_activities', ['activity_id'], ['id'], ['onDelete' => 'SET NULL']);
        }

        if (!$schema->hasTable('kimai2_ext_lexware_contact_mapping')) {
            $table = $schema->createTable('kimai2_ext_lexware_contact_mapping');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('lexware_contact_id', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('customer_id', 'integer', ['notnull' => true]);
            $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['lexware_contact_id']);
            $table->addForeignKeyConstraint('kimai2_customers', ['customer_id'], ['id'], ['onDelete' => 'CASCADE']);
        }

        if (!$schema->hasTable('kimai2_ext_lexware_webhook_event')) {
            $table = $schema->createTable('kimai2_ext_lexware_webhook_event');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('event_type', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('resource_id', 'string', ['notnull' => false, 'length' => 100]);
            $table->addColumn('received_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('signature_valid', 'boolean', ['notnull' => true]);
            $table->addColumn('processed', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('error_message', 'text', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'kimai2_ext_lexware_order_confirmation_line',
            'kimai2_ext_lexware_order_confirmation',
            'kimai2_ext_lexware_contact_mapping',
            'kimai2_ext_lexware_webhook_event',
        ] as $tableName) {
            if ($schema->hasTable($tableName)) {
                $schema->dropTable($tableName);
            }
        }
    }
}
