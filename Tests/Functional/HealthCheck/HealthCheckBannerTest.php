<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\HealthCheck;

use App\Entity\User;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResult;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResultStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\SignsLicenseArtefacts;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\WebTestCase;

final class HealthCheckBannerTest extends WebTestCase
{
    use SignsLicenseArtefacts;

    public function testAFailingApiKeyCheckShowsOnTheTriageScreen(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->service(HealthCheckResultStore::class)->store(
            new HealthCheckResult('api_key', new \DateTimeImmutable(), false, 'no longer authenticates'),
        );

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-health-check="api_key"][data-health-check-state="failed"]'));
    }

    public function testAStaleLicenseCheckShowsOnTheInvoiceScreen(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->service(HealthCheckResultStore::class)->store(
            new HealthCheckResult('license', new \DateTimeImmutable('-10 days'), true, null),
        );

        $crawler = $browser->request('GET', $this->url('lexware_sync_invoices'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-health-check="license"][data-health-check-state="stale"]'));
    }

    public function testARecentPassingResultShowsNoBanner(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->service(HealthCheckResultStore::class)->store(
            new HealthCheckResult('api_key', new \DateTimeImmutable(), true, null),
        );

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-health-check]'));
    }

    public function testAFailingApiKeyCheckAlsoShowsOnTheInvoiceAssignScreen(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->service(HealthCheckResultStore::class)->store(
            new HealthCheckResult('api_key', new \DateTimeImmutable(), false, 'no longer authenticates'),
        );
        $trackedInvoice = $this->givenAPendingTrackedInvoice('RE-2026-900', 'Contact GmbH');

        $crawler = $browser->request('GET', $this->url('lexware_sync_invoices_assign', ['id' => $trackedInvoice->getId()]));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-health-check="api_key"][data-health-check-state="failed"]'));
    }

    private function givenAPendingOrderConfirmation(string $voucherNumber, string $contactName): TrackedOrderConfirmation
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-' . $voucherNumber);
        $orderConfirmation->updateFromLexwarePayload(
            $voucherNumber,
            'Order confirmation for a health check banner test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            $contactName,
            '{}',
            null,
        );

        $this->entityManager()->persist($orderConfirmation);
        $this->entityManager()->flush();

        return $orderConfirmation;
    }

    private function givenAPendingTrackedInvoice(string $voucherNumber, string $contactName): TrackedInvoice
    {
        $orderConfirmation = $this->givenAPendingOrderConfirmation('AB-for-' . $voucherNumber, $contactName);

        $trackedInvoice = new TrackedInvoice('lexware-invoice-' . $voucherNumber, $orderConfirmation);
        $trackedInvoice->updateFromLexwarePayload(
            $voucherNumber,
            new \DateTimeImmutable('2026-09-01'),
            $contactName,
            '{"address":{"contactId":"contact-1"},"lineItems":[]}',
            null,
        );

        $this->entityManager()->persist($trackedInvoice);
        $this->entityManager()->flush();

        return $trackedInvoice;
    }
}
