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

    public function testTheResolveLinesButtonOnlyAppearsWhileLineReadingIsOn(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $orderConfirmation = $this->givenAChangedConvertedOrderConfirmation();
        $link = $this->url('lexware_sync_triage_resolve_lines', ['id' => $orderConfirmation->getId()]);
        $listUrl = $this->url('lexware_sync_triage') . '?status=converted';

        $crawler = $browser->request('GET', $listUrl);
        self::assertCount(0, $crawler->filter('a[href="' . $link . '"]'), 'A button leading to a screen that only redirects back is worse than no button.');

        $this->configure('lexware_sync.read_lines_enabled', true);

        $crawler = $browser->request('GET', $listUrl);
        self::assertCount(1, $crawler->filter('a[href="' . $link . '"]'));
    }

    private function givenAChangedConvertedOrderConfirmation(): TrackedOrderConfirmation
    {
        $project = $this->factory()->createProject();

        $orderConfirmation = new TrackedOrderConfirmation('lexware-triage-changed');
        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-020',
            'Order confirmation for a test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            'Contact GmbH',
            '{"lineItems":[]}',
            null
        );
        $orderConfirmation->setProject($project);
        $orderConfirmation->markAutomaticallyConverted();

        $this->entityManager()->persist($orderConfirmation);
        $this->entityManager()->flush();

        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-020',
            'Order confirmation for a test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            'Contact GmbH',
            '{"lineItems":[{"type":"custom","name":"Development"}]}',
            null
        );
        $this->entityManager()->flush();

        self::assertTrue($orderConfirmation->hasChangedAfterConversion());

        return $orderConfirmation;
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
