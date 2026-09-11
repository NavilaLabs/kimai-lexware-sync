<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Client\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Exception\License\LicenseServiceUnavailable;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LicenseClient
{
    private const TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $serviceUrl,
    ) {
    }

    public function fetch(string $licenseKey, string $version): string
    {
        try {
            $response = $this->httpClient->request('POST', $this->serviceUrl, [
                'json' => ['key' => $licenseKey, 'version' => $version],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new LicenseServiceUnavailable('The licensing service answered with status ' . $response->getStatusCode() . '.');
            }

            $decoded = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface $exception) {
            throw new LicenseServiceUnavailable('The licensing service could not be reached: ' . $exception->getMessage(), 0, $exception);
        }

        $artefact = \is_array($decoded) ? ($decoded['license'] ?? null) : null;
        if (!\is_string($artefact) || $artefact === '') {
            throw new LicenseServiceUnavailable('The licensing service answered without a license artefact.');
        }

        return $artefact;
    }
}
