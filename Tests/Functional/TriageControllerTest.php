<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional;

use App\Entity\User;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class TriageControllerTest extends WebTestCase
{
    public function testTheTriageScreenListsAPendingOrderConfirmation(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenPendingOrderConfirmation('AB-2026-010', 'Contact GmbH');

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('AB-2026-010', $crawler->filter('body')->text());
        self::assertStringContainsString('Contact GmbH', $crawler->filter('body')->text());
    }

    public function testAUserWithoutThePermissionIsRefused(): void
    {
        $browser = $this->browserLoggedInAs('regular', [User::ROLE_USER]);

        $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $browser = $this->browser();
        $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseRedirects();
    }

    private function givenPendingOrderConfirmation(string $voucherNumber, string $contactName): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-' . $voucherNumber);
        $orderConfirmation->updateFromLexwarePayload(
            $voucherNumber,
            'Order confirmation for a test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            $contactName,
            '{}',
            null
        );

        $this->entityManager()->persist($orderConfirmation);
        $this->entityManager()->flush();
    }
}
