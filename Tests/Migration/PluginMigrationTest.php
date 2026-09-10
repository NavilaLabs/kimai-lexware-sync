<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Migration;

final class PluginMigrationTest extends MigrationTestCase
{
    public function testAFreshInstallationCreatesEveryTableWithTheRequiredPrefix(): void
    {
        self::assertSame([], $this->tableNames('kimai2_ext_lexware_%'), 'The scratch database was not clean before migrating.');

        $before = $this->tableNames();

        $this->migrate();

        $created = $this->tablesCreatedSince($before);

        foreach ($created as $table) {
            self::assertStringStartsWith(
                'kimai2_ext_',
                $table,
                'Kimai requires every table a plugin creates to carry the kimai2_ext_ prefix, and ' . $table . ' does not.'
            );
        }

        self::assertSame([
            'kimai2_ext_lexware_contact_mapping',
            'kimai2_ext_lexware_invoice',
            'kimai2_ext_lexware_invoice_timesheet',
            'kimai2_ext_lexware_order_confirmation',
            'kimai2_ext_lexware_order_confirmation_line',
            'kimai2_ext_lexware_webhook_event',
        ], $created);
    }

    public function testUpgradingAnInstallationThatAlreadyHoldsDataKeepsThatData(): void
    {
        $this->migrate(self::FIRST_VERSION);

        self::assertNotContains(
            'contact_name',
            $this->columnNames('kimai2_ext_lexware_order_confirmation'),
            'The starting point of this test is a schema from before the contact name was added.'
        );

        $this->givenTrackedOrderConfirmation('lexware-upgrade-1', 'AB-2026-900');

        $this->migrate();

        $statement = $this->connection->query(
            "SELECT voucher_number, contact_name FROM kimai2_ext_lexware_order_confirmation WHERE lexware_id = 'lexware-upgrade-1'"
        );
        self::assertNotFalse($statement);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'The tracked order confirmation did not survive the upgrade.');
        self::assertSame('AB-2026-900', $row['voucher_number']);
        self::assertSame('', $row['contact_name'], 'A row that predates the column should end up with an empty contact name, not with a broken migration.');
    }

    public function testUpgradingAnInstallationWithExistingLinesFillsTheNewColumnsWithSafeDefaults(): void
    {
        $this->migrate(self::FIRST_VERSION);
        $this->givenTrackedOrderConfirmation('lexware-upgrade-2', 'AB-2026-901');
        $idStatement = $this->connection->query(
            "SELECT id FROM kimai2_ext_lexware_order_confirmation WHERE lexware_id = 'lexware-upgrade-2'"
        );
        self::assertNotFalse($idStatement);
        $orderConfirmationId = $idStatement->fetchColumn();
        self::assertNotFalse($orderConfirmationId);
        $this->givenTrackedOrderConfirmationLine((int) $orderConfirmationId);

        self::assertNotContains(
            'quantity',
            $this->columnNames('kimai2_ext_lexware_order_confirmation_line'),
            'The starting point of this test is a schema from before the budget fields were added.'
        );

        $this->migrate();

        $statement = $this->connection->query(
            'SELECT quantity, unit_name, net_amount, is_hour_line, removed_from_source
             FROM kimai2_ext_lexware_order_confirmation_line
             WHERE order_confirmation_id = ' . (int) $orderConfirmationId
        );
        self::assertNotFalse($statement);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'The tracked order confirmation line did not survive the upgrade.');
        self::assertSame(0.0, (float) $row['quantity']);
        self::assertSame('', $row['unit_name']);
        self::assertSame(0.0, (float) $row['net_amount']);
        self::assertSame(0, (int) $row['is_hour_line']);
        self::assertSame(0, (int) $row['removed_from_source']);
    }

    private function givenTrackedOrderConfirmationLine(int $orderConfirmationId): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO kimai2_ext_lexware_order_confirmation_line
                (order_confirmation_id, position, type, name, description, matched)
             VALUES (:order_confirmation_id, 0, :type, :name, :description, 0)'
        );

        $statement->execute([
            'order_confirmation_id' => $orderConfirmationId,
            'type' => 'custom',
            'name' => 'Development',
            'description' => null,
        ]);
    }

    private function givenTrackedOrderConfirmation(string $lexwareId, string $voucherNumber): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO kimai2_ext_lexware_order_confirmation
                (lexware_id, voucher_number, title, voucher_date, lexware_contact_id, raw_payload, status,
                 first_seen_at, last_synchronized_at, changed_after_conversion)
             VALUES (:lexware_id, :voucher_number, :title, :voucher_date, :contact_id, :payload, :status,
                 :first_seen_at, :last_synchronized_at, 0)'
        );

        $statement->execute([
            'lexware_id' => $lexwareId,
            'voucher_number' => $voucherNumber,
            'title' => 'Order confirmation from before the upgrade',
            'voucher_date' => '2026-09-01 00:00:00',
            'contact_id' => 'contact-1',
            'payload' => '{}',
            'status' => 'pending',
            'first_seen_at' => '2026-09-01 00:00:00',
            'last_synchronized_at' => '2026-09-01 00:00:00',
        ]);
    }
}
