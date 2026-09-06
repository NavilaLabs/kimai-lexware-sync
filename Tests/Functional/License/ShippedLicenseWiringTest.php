<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\AlwaysLicensedGate;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseGate;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\ShippedWiringKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShippedLicenseWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return ShippedWiringKernel::class;
    }

    public function testThePluginShipsWithTheCheckSwitchedOff(): void
    {
        self::bootKernel();

        self::assertInstanceOf(AlwaysLicensedGate::class, self::getContainer()->get(LicenseGate::class));
    }
}
