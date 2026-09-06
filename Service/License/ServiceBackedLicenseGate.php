<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class ServiceBackedLicenseGate implements LicenseGate
{
    private const FAILURE_MEMORY_KEY = 'lexware_sync.license_fetch_failed';
    private const FAILURE_MEMORY_SECONDS = 900;

    public function __construct(
        private readonly LexwareSyncConfiguration $configuration,
        private readonly LicenseStore $store,
        private readonly LicenseEvaluator $evaluator,
        private readonly LicenseClient $client,
        private readonly PluginVersion $pluginVersion,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function verdict(): LicenseVerdict
    {
        $licenseKey = $this->configuration->getLicenseKey();
        if ($licenseKey === '') {
            return LicenseVerdict::refused(LicenseState::NoKeyConfigured);
        }

        $version = $this->pluginVersion->current();
        $now = new \DateTimeImmutable();

        $token = $this->evaluator->usableToken($this->store->storedToken($licenseKey), $version, $now);
        if ($token !== null) {
            return $this->evaluator->verdictFor($token);
        }

        if ($this->fetchFailedRecently()) {
            return LicenseVerdict::refused(LicenseState::Unreachable);
        }

        try {
            $raw = $this->client->fetch($licenseKey, $version);
        } catch (LicenseServiceUnavailable $exception) {
            $this->logger->warning('The licensing service could not be reached: ' . $exception->getMessage());
            $this->rememberFailure();

            return LicenseVerdict::refused(LicenseState::Unreachable);
        }

        $fresh = $this->evaluator->usableToken($raw, $version, $now);
        if ($fresh === null) {
            $this->logger->error('The licensing service answered with an artefact this installation cannot use.');
            $this->rememberFailure();

            return LicenseVerdict::refused(LicenseState::Unreachable);
        }

        $this->store->store($licenseKey, $raw);

        return $this->evaluator->verdictFor($fresh);
    }

    private function fetchFailedRecently(): bool
    {
        return $this->cache->get(self::FAILURE_MEMORY_KEY, static function (ItemInterface $item): bool {
            // Nothing is remembered, so nothing failed. The entry expires straight away rather
            // than leaving a permanent negative answer behind.
            $item->expiresAfter(1);

            return false;
        }) === true;
    }

    private function rememberFailure(): void
    {
        $this->cache->delete(self::FAILURE_MEMORY_KEY);
        $this->cache->get(self::FAILURE_MEMORY_KEY, static function (ItemInterface $item): bool {
            $item->expiresAfter(self::FAILURE_MEMORY_SECONDS);

            return true;
        });
    }
}
