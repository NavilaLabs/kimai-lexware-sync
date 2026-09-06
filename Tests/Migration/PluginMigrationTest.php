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
