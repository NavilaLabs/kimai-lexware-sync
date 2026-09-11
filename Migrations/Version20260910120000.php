<?php

declare(strict_types=1);

namespace KimaiLexwareSyncBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260910120000 extends AbstractMigration
{
    private const TABLE_NAME = 'kimai2_ext_lexware_order_confirmation_line';

    public function getDescription(): string
    {
        return 'Store quantity, unit name, net amount and hour-line classification on every tracked order confirmation line, for budget derivation';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(self::TABLE_NAME);

        if (!$table->hasColumn('quantity')) {
            $table->addColumn('quantity', 'float', ['notnull' => true, 'default' => 0]);
        }
        if (!$table->hasColumn('unit_name')) {
            $table->addColumn('unit_name', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);
        }
        if (!$table->hasColumn('net_amount')) {
            $table->addColumn('net_amount', 'float', ['notnull' => true, 'default' => 0]);
        }
        if (!$table->hasColumn('is_hour_line')) {
            $table->addColumn('is_hour_line', 'boolean', ['notnull' => true, 'default' => false]);
        }
        if (!$table->hasColumn('removed_from_source')) {
            $table->addColumn('removed_from_source', 'boolean', ['notnull' => true, 'default' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(self::TABLE_NAME);

        foreach (['quantity', 'unit_name', 'net_amount', 'is_hour_line', 'removed_from_source'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
}
