<?php

declare(strict_types=1);

namespace KimaiLexwareSyncBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260905160000 extends AbstractMigration
{
    private const TABLE_NAMES = [
        'kimai2_ext_lexware_order_confirmation',
        'kimai2_ext_lexware_invoice',
    ];

    public function getDescription(): string
    {
        return 'Store the Lexware contact name on every tracked document so the review screens can search for it';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLE_NAMES as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('contact_name')) {
                $table->addColumn('contact_name', 'string', ['notnull' => true, 'length' => 255]);
            }
        }
    }

    public function postUp(Schema $schema): void
    {
        foreach (self::TABLE_NAMES as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $this->connection->executeStatement(\sprintf(
                "UPDATE %s SET contact_name = LEFT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.address.name')), ''), 255) WHERE contact_name = ''",
                $tableName,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLE_NAMES as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);
            if ($table->hasColumn('contact_name')) {
                $table->dropColumn('contact_name');
            }
        }
    }
}
