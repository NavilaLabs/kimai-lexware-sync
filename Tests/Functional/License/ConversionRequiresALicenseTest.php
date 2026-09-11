<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use App\Entity\Timesheet;
use App\Entity\User;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceLineShape;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\License\LicenseState;
use KimaiPlugin\KimaiLexwareSyncBundle\Exception\License\LicenseRequiredException;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceTimesheetRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceProcessor;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationProcessor;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;

final class ConversionRequiresALicenseTest extends FunctionalTestCase
{
    public function testAnOrderConfirmationIsNotConvertedWithoutALicense(): void
    {
        $this->configure('lexware_sync.license_key', '');
        $orderConfirmation = $this->trackedOrderConfirmation();

        try {
            $this->service(OrderConfirmationProcessor::class)->convert(
                $orderConfirmation,
                new LexwarePayload($this->payload()),
                null,
                '',
                false
            );
            self::fail('The conversion should have been refused.');
        } catch (LicenseRequiredException $exception) {
            self::assertSame(LicenseState::NoKeyConfigured, $exception->verdict()->state);
        }

        self::assertNull($orderConfirmation->getProject());
    }

    public function testAnInvoiceIsNotWrittenBackWithoutALicense(): void
    {
        $this->configure('lexware_sync.license_key', '');

        [$trackedInvoice, $timesheet, $user] = $this->pendingInvoiceWithOneTimesheet();

        try {
            $this->service(InvoiceProcessor::class)->convert(
                $trackedInvoice,
                [$timesheet],
                InvoiceLineShape::PerTimesheet,
                false,
                false,
                $user
            );
            self::fail('The invoice should not have been written back.');
        } catch (LicenseRequiredException $exception) {
            self::assertSame(LicenseState::NoKeyConfigured, $exception->verdict()->state);
        }

        self::assertSame(0, $this->lexware()->requestCount(), 'Nothing may reach Lexware while unlicensed.');
    }

    public function testAnInvoiceThatAlreadyExistsInLexwareIsNotConfirmedWithoutALicense(): void
    {
        $this->configure('lexware_sync.license_key', '');

        [$trackedInvoice, $timesheet, $user] = $this->pendingInvoiceWithOneTimesheet();

        try {
            $this->service(InvoiceProcessor::class)->confirmExisting(
                $trackedInvoice,
                'lexware-invoice-created-elsewhere',
                [$timesheet],
                true,
                $user
            );
            self::fail('Confirming an existing invoice should have been refused.');
        } catch (LicenseRequiredException $exception) {
            self::assertSame(LicenseState::NoKeyConfigured, $exception->verdict()->state);
        }

        self::assertTrue($trackedInvoice->getStatus()->isPending(), 'The tracked invoice may not be marked converted.');
        self::assertNull($trackedInvoice->getCreatedInvoiceLexwareId());
        self::assertFalse($timesheet->isExported(), 'The timesheets may not be flagged exported.');
        self::assertSame(
            [],
            $this->service(TrackedInvoiceTimesheetRepository::class)->findBy(['trackedInvoice' => $trackedInvoice]),
            'No timesheet may be linked to the tracked invoice.'
        );
    }

    /**
     * @return array{TrackedInvoice, Timesheet, User}
     */
    private function pendingInvoiceWithOneTimesheet(): array
    {
        $orderConfirmation = $this->trackedOrderConfirmation();
        $customer = $this->factory()->createCustomer('Contact GmbH');
        $project = $this->factory()->createProject($customer);
        $orderConfirmation->setCustomer($customer);
        $orderConfirmation->setProject($project);

        $trackedInvoice = new TrackedInvoice('lexware-invoice-1', $orderConfirmation);
        $trackedInvoice->updateFromLexwarePayload(
            'RE-2026-500',
            new \DateTimeImmutable('2026-09-01'),
            'Contact GmbH',
            '{"address":{"contactId":"contact-1"},"lineItems":[]}',
            null
        );
        $this->entityManager()->persist($trackedInvoice);
        $this->entityManager()->flush();

        $activity = $this->factory()->createActivity($project);
        $user = $this->factory()->createUser('converter');

        return [$trackedInvoice, $this->factory()->createTimesheet($project, $activity, $user), $user];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'address' => ['contactId' => 'contact-1', 'name' => 'Contact GmbH'],
            'lineItems' => [],
        ];
    }

    private function trackedOrderConfirmation(): TrackedOrderConfirmation
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-license-1');
        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-500',
            'Order confirmation for a license test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            'Contact GmbH',
            '{}',
            null
        );

        $this->entityManager()->persist($orderConfirmation);
        $this->entityManager()->flush();

        return $orderConfirmation;
    }
}
