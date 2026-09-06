<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use App\Entity\User;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\SignsLicenseArtefacts;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\WebTestCase;

final class LicenseBannerTest extends WebTestCase
{
    use SignsLicenseArtefacts;

    public function testTheTriageScreenExplainsAMissingLicense(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->configure('lexware_sync.license_key', '');

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-license-state="no_key_configured"]'));
    }

    public function testTheInvoiceScreenExplainsAMissingLicense(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->configure('lexware_sync.license_key', '');

        $crawler = $browser->request('GET', $this->url('lexware_sync_invoices'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-license-state="no_key_configured"]'));
    }

    public function testTheInvoiceAssignScreenExplainsAMissingLicenseAndDisablesConversion(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->configure('lexware_sync.license_key', '');
        $trackedInvoice = $this->givenAPendingTrackedInvoice('RE-2026-100', 'Contact GmbH');

        $crawler = $browser->request('GET', $this->url('lexware_sync_invoices_assign', ['id' => $trackedInvoice->getId()]));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-license-state="no_key_configured"]'));

        $submitButton = $crawler->filter('button.btn-primary[type="submit"]');
        self::assertCount(1, $submitButton);
        self::assertNotNull($submitButton->attr('disabled'));
    }

    public function testTheTriageScreenDisablesTheConvertButtonButNotTheRejectButtonWithoutALicense(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->configure('lexware_sync.license_key', '');
        $this->givenAPendingOrderConfirmation('AB-2026-100', 'Contact GmbH');

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseIsSuccessful();

        $convertButton = $crawler->filter('form[action$="/convert"] button');
        self::assertCount(1, $convertButton);
        self::assertNotNull($convertButton->attr('disabled'));

        $rejectButton = $crawler->filter('form[action$="/reject"] button');
        self::assertCount(1, $rejectButton);
        self::assertNull($rejectButton->attr('disabled'));
    }

    public function testTheTriageScreenShowsNoBannerAndAnEnabledConvertButtonWhenLicensed(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->givenAPendingOrderConfirmation('AB-2026-200', 'Contact GmbH');

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-license-state]'));

        $convertButton = $crawler->filter('form[action$="/convert"] button');
        self::assertCount(1, $convertButton);
        self::assertNull($convertButton->attr('disabled'));
    }

    public function testConvertingAnOrderConfirmationWithoutALicenseFlashesWhereToEnterTheKey(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $browser->disableReboot();
        $this->configure('lexware_sync.license_key', '');
        $this->givenAPendingOrderConfirmation('AB-2026-300', 'Contact GmbH');

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));
        $form = $crawler->filter('form[action$="/convert"]')->form();
        $browser->submit($form);
        $browser->followRedirect();

        $page = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('system configuration', $page);
        self::assertStringNotContainsString('This installation has no confirmed license', $page);
    }

    public function testConvertingWithARejectedLicenseFlashesTheReasonInstead(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $browser->disableReboot();
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->service(LicenseStore::class)->store('key-one', $this->refusal('expired'));
        $this->givenAPendingOrderConfirmation('AB-2026-400', 'Contact GmbH');

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));
        $form = $crawler->filter('form[action$="/convert"]')->form();
        $browser->submit($form);
        $browser->followRedirect();

        $page = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('subscription has expired', $page);
        self::assertStringNotContainsString('system configuration', $page);
        self::assertStringNotContainsString('This installation has no confirmed license', $page);
    }

    private function givenAPendingOrderConfirmation(string $voucherNumber, string $contactName): TrackedOrderConfirmation
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-' . $voucherNumber);
        $orderConfirmation->updateFromLexwarePayload(
            $voucherNumber,
            'Order confirmation for a license banner test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            $contactName,
            '{}',
            null
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
            null
        );

        $this->entityManager()->persist($trackedInvoice);
        $this->entityManager()->flush();

        return $trackedInvoice;
    }
}
