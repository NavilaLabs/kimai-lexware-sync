<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional;

use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcileOrderConfirmationsCommandTest extends FunctionalTestCase
{
    public function testAVoucherThatIsNotTrackedYetIsFetchedAndStored(): void
    {
        $this->lexware()->willRespondWith('GET', '/v1/voucherlist', $this->voucherList([
            ['id' => 'lexware-100', 'updatedDate' => '2026-09-01T10:00:00.000+02:00'],
        ]));
        $this->lexware()->willRespondWith('GET', '/v1/order-confirmations/lexware-100', $this->orderConfirmation('lexware-100', 'AB-2026-100'));

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Reconciled 1 order confirmation(s), 0 failed.', $tester->getDisplay());

        $stored = $this->service(TrackedOrderConfirmationRepository::class)->findByLexwareId('lexware-100');
        self::assertNotNull($stored);
        self::assertSame('AB-2026-100', $stored->getVoucherNumber());
    }

    public function testAVoucherThatHasNotChangedIsNotFetchedAgain(): void
    {
        $this->lexware()->willRespondWith('GET', '/v1/voucherlist', $this->voucherList([
            ['id' => 'lexware-101', 'updatedDate' => '2026-09-01T10:00:00.000+02:00'],
        ]));
        $this->lexware()->willRespondWith('GET', '/v1/order-confirmations/lexware-101', $this->orderConfirmation('lexware-101', 'AB-2026-101'));
        $this->runCommand();

        $this->lexware()->willRespondWith('GET', '/v1/voucherlist', $this->voucherList([
            ['id' => 'lexware-101', 'updatedDate' => '2026-09-01T10:00:00.000+02:00'],
        ]));
        $tester = $this->runCommand();

        // No stub for the detail endpoint this time: fetching it again would fail the test.
        self::assertStringContainsString('Reconciled 0 order confirmation(s), 0 failed.', $tester->getDisplay());
    }

    public function testAFailingVoucherIsReportedWithoutStoppingTheOthers(): void
    {
        $this->lexware()->willRespondWith('GET', '/v1/voucherlist', $this->voucherList([
            ['id' => 'lexware-broken', 'updatedDate' => '2026-09-01T10:00:00.000+02:00'],
            ['id' => 'lexware-102', 'updatedDate' => '2026-09-01T10:00:00.000+02:00'],
        ]));
        $this->lexware()->willRespondWith('GET', '/v1/order-confirmations/lexware-broken', ['error' => 'gone'], 404);
        $this->lexware()->willRespondWith('GET', '/v1/order-confirmations/lexware-102', $this->orderConfirmation('lexware-102', 'AB-2026-102'));

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Reconciled 1 order confirmation(s), 1 failed.', $tester->getDisplay());
        self::assertNotNull($this->service(TrackedOrderConfirmationRepository::class)->findByLexwareId('lexware-102'));
    }

    private function runCommand(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('kimai:lexware-sync:reconcile'));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @param list<array<string, mixed>> $vouchers
     *
     * @return array<string, mixed>
     */
    private function voucherList(array $vouchers): array
    {
        return ['content' => $vouchers, 'last' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderConfirmation(string $lexwareId, string $voucherNumber): array
    {
        return [
            'id' => $lexwareId,
            'voucherNumber' => $voucherNumber,
            'voucherDate' => '2026-09-01T00:00:00.000+02:00',
            'updatedDate' => '2026-09-01T10:00:00.000+02:00',
            'title' => 'Order confirmation for a test',
            'address' => ['contactId' => 'contact-1', 'name' => 'Contact GmbH'],
            'lineItems' => [
                ['type' => 'custom', 'name' => 'Development', 'description' => 'Building the thing'],
            ],
            'totalPrice' => ['totalNetAmount' => 1000.0, 'currency' => 'EUR'],
        ];
    }
}
