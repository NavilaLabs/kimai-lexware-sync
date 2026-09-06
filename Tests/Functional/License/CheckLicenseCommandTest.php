<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\SignsLicenseArtefacts;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckLicenseCommandTest extends FunctionalTestCase
{
    use SignsLicenseArtefacts;

    public function testWithoutAKeyTheCommandSaysSoAndFails(): void
    {
        $this->configure('lexware_sync.license_key', '');

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No license key', $tester->getDisplay());
    }

    public function testAnApprovalIsFetchedAndStored(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['license' => $this->approval()]);

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertNotNull($this->service(LicenseStore::class)->storedToken('key-one'));
    }

    public function testARefusalIsStoredAndReportedAsAFailure(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['license' => $this->refusal('expired')]);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('expired', $tester->getDisplay());
        self::assertNotNull($this->service(LicenseStore::class)->storedToken('key-one'));
    }

    public function testAFreshArtefactWhoseRecheckDateHasNotPassedIsLeftAlone(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->service(LicenseStore::class)->store('key-one', $this->approvalRecheckedAfter('2099-01-01T00:00:00+01:00'));

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(0, $this->licenseService()->requestCount());
    }

    public function testAnUnreachableServiceFailsWithoutDiscardingWhatIsStored(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $storedBeforehand = $this->approvalRecheckedAfter('2020-01-01T00:00:00+01:00');
        $this->service(LicenseStore::class)->store('key-one', $storedBeforehand);
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['message' => 'down'], 500);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame($storedBeforehand, $this->service(LicenseStore::class)->storedToken('key-one'));
    }

    public function testAStoredRefusalWhoseRecheckDateHasNotPassedIsReportedAsAFailure(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->service(LicenseStore::class)->store(
            'key-one',
            $this->refusalRecheckedAfter('expired', '2099-01-01T00:00:00+01:00')
        );

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $this->licenseService()->requestCount());
        self::assertStringContainsString('expired', $tester->getDisplay());
    }

    private function runCommand(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('kimai:lexware-sync:check-license'));
        $tester->execute([]);

        return $tester;
    }
}
