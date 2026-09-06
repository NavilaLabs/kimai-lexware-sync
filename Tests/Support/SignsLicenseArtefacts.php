<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\PluginVersion;

/**
 * The key pair below exists only for tests and is committed on purpose. Its public half is
 * repeated in Tests/config/license_enforcement.yaml, because a YAML file cannot read a PHP
 * constant, and the two must match.
 */
trait SignsLicenseArtefacts
{
    private const TEST_SECRET_KEY = 'wBE/04pkhGgcCvOhF/2KQ28yF4KrFPVqChyBu/60mEqMwafSOT1zMPDYwnzibJAUmhTWNVRz1EPMSAS48cKKtQ==';
    private const TEST_PUBLIC_KEY = 'jMGn0jk9czDw2MJ84myQFJoU1jVUc9RDzEgEuPHCirU=';

    protected function licenseService(): FakeLicenseHttpClient
    {
        return $this->service(FakeLicenseHttpClient::class);
    }

    protected function givenAConfirmedLicense(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->service(LicenseStore::class)->store('key-one', $this->approval());
    }

    protected function approval(): string
    {
        return $this->signArtefact(['licensed' => true, 'customer' => 'Example GmbH', 'version' => $this->installedVersion()]);
    }

    protected function refusal(string $reason): string
    {
        return $this->signArtefact([
            'licensed' => false,
            'customer' => 'Example GmbH',
            'version' => $this->installedVersion(),
            'reason' => $reason,
        ]);
    }

    protected function approvalRecheckedAfter(string $recheckAfter): string
    {
        return $this->signArtefact([
            'licensed' => true,
            'customer' => 'Example GmbH',
            'version' => $this->installedVersion(),
            'recheck_after' => $recheckAfter,
        ]);
    }

    protected function installedVersion(): string
    {
        return $this->service(PluginVersion::class)->current();
    }

    protected function testPublicKey(): string
    {
        return self::TEST_PUBLIC_KEY;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function signArtefact(array $body): string
    {
        $secretKey = base64_decode(self::TEST_SECRET_KEY, true);
        if ($secretKey === false || $secretKey === '') {
            throw new \RuntimeException('The test secret key is not valid base64.');
        }

        $encoded = self::encodePart(json_encode($body, JSON_THROW_ON_ERROR));

        return $encoded . '.' . self::encodePart(sodium_crypto_sign_detached($encoded, $secretKey));
    }

    private static function encodePart(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
