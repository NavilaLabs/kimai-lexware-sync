<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseState;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\ServiceBackedLicenseGate;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\SignsLicenseArtefacts;

final class ServiceBackedLicenseGateTest extends FunctionalTestCase
{
    use SignsLicenseArtefacts;

    public function testTheCommittedPublicKeyMatchesWhatTheTestContainerAccepts(): void
    {
        self::assertSame([$this->testPublicKey()], $this->container()->getParameter('lexware_sync.license_public_keys'));
    }

    public function testWithoutAKeyNothingIsAskedAndNothingIsAllowed(): void
    {
        $this->configure('lexware_sync.license_key', '');

        $verdict = $this->service(ServiceBackedLicenseGate::class)->verdict();

        self::assertSame(LicenseState::NoKeyConfigured, $verdict->state);
        self::assertSame(0, $this->licenseService()->requestCount());
    }

    public function testAStoredApprovalIsUsedWithoutAskingAgain(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->service(LicenseStore::class)->store('key-one', $this->approval());

        $verdict = $this->service(ServiceBackedLicenseGate::class)->verdict();

        self::assertTrue($verdict->allowsConversion());
        self::assertSame('Example GmbH', $verdict->customer);
        self::assertSame(0, $this->licenseService()->requestCount());
    }

    public function testWithNothingStoredTheServiceIsAskedOnceAndTheAnswerIsKept(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['license' => $this->approval()]);

        self::assertTrue($this->service(ServiceBackedLicenseGate::class)->verdict()->allowsConversion());
        self::assertSame(1, $this->licenseService()->requestCount());
        self::assertNotNull($this->service(LicenseStore::class)->storedToken('key-one'));
    }

    public function testAnUnreachableServiceIsReportedAndNotAskedAgainImmediately(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['message' => 'down'], 500);

        $gate = $this->service(ServiceBackedLicenseGate::class);

        self::assertSame(LicenseState::Unreachable, $gate->verdict()->state);
        self::assertSame(LicenseState::Unreachable, $gate->verdict()->state);
        self::assertSame(1, $this->licenseService()->requestCount());
    }

    public function testARefusalIsKeptSoThatClickingAgainDoesNotAskAgain(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['license' => $this->refusal('expired')]);

        $gate = $this->service(ServiceBackedLicenseGate::class);

        self::assertSame(LicenseState::Rejected, $gate->verdict()->state);
        self::assertSame('expired', $gate->verdict()->reason);
        self::assertSame(1, $this->licenseService()->requestCount());
    }
}
