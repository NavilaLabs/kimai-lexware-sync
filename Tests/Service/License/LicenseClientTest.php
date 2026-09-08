<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseServiceUnavailable;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FakeLicenseHttpClient;
use PHPUnit\Framework\TestCase;

final class LicenseClientTest extends TestCase
{
    private const SERVICE_URL = 'https://licensing.example.com/v1/check';

    public function testTheArtefactIsReturnedAndOnlyTwoFieldsAreSent(): void
    {
        $httpClient = new FakeLicenseHttpClient();
        $httpClient->willRespondWith('POST', '/v1/check', ['license' => 'body.signature']);

        $client = new LicenseClient($httpClient, self::SERVICE_URL);

        self::assertSame('body.signature', $client->fetch('key-one', '1.0.0'));

        $request = $httpClient->recordedRequests()[0];
        self::assertSame(['key' => 'key-one', 'version' => '1.0.0'], $request->decodedBody());
    }

    public function testAResponseWithoutAnArtefactCountsAsUnavailable(): void
    {
        $httpClient = new FakeLicenseHttpClient();
        $httpClient->willRespondWith('POST', '/v1/check', ['unexpected' => true]);

        $this->expectException(LicenseServiceUnavailable::class);

        (new LicenseClient($httpClient, self::SERVICE_URL))->fetch('key-one', '1.0.0');
    }

    public function testAnErrorStatusCountsAsUnavailable(): void
    {
        $httpClient = new FakeLicenseHttpClient();
        $httpClient->willRespondWith('POST', '/v1/check', ['message' => 'boom'], 500);

        $this->expectException(LicenseServiceUnavailable::class);

        (new LicenseClient($httpClient, self::SERVICE_URL))->fetch('key-one', '1.0.0');
    }

    public function testAResponseThatIsNotJsonCountsAsUnavailable(): void
    {
        $httpClient = new FakeLicenseHttpClient();
        $httpClient->willRespondWithBody('POST', '/v1/check', 'not json at all');

        $this->expectException(LicenseServiceUnavailable::class);

        (new LicenseClient($httpClient, self::SERVICE_URL))->fetch('key-one', '1.0.0');
    }
}
