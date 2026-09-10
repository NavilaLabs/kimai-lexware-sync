<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\AlwaysLicensedGate;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseGate;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationProcessor;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\ShippedWiringKernel;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\InteractsWithKimai;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShippedLicenseWiringTest extends KernelTestCase
{
    use InteractsWithKimai;

    protected static function getKernelClass(): string
    {
        return ShippedWiringKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    public function testThePluginShipsWithTheCheckSwitchedOff(): void
    {
        self::assertInstanceOf(AlwaysLicensedGate::class, $this->container()->get(LicenseGate::class));
    }

    public function testAConversionSucceedsWithNoLicenseKeyAndNoStoredArtefact(): void
    {
        $licenseKey = $this->service(LexwareSyncConfiguration::class)->getLicenseKey();
        self::assertSame('', $licenseKey, 'This test only says something while nothing is licensed.');
        self::assertNull(
            $this->service(LicenseStore::class)->storedToken($licenseKey),
            'This test only says something while no license artefact is stored.'
        );

        $orderConfirmation = $this->trackedOrderConfirmation();

        $this->service(OrderConfirmationProcessor::class)->convert(
            $orderConfirmation,
            new LexwarePayload(['address' => ['contactId' => 'contact-1', 'name' => 'Contact GmbH'], 'lineItems' => []]),
            null,
            '',
            false
        );

        self::assertNotNull($orderConfirmation->getProject(), 'A shipped installation must convert without any license.');
        self::assertNotNull($orderConfirmation->getCustomer());
        self::assertTrue($orderConfirmation->getStatus()->isConverted());
    }

    private function trackedOrderConfirmation(): TrackedOrderConfirmation
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-shipped-wiring-1');
        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-700',
            'Order confirmation for the shipped wiring test',
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
