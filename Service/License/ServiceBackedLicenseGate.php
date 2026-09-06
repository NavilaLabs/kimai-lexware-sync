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

        $remembered = $this->rememberedFailure();
        if ($remembered !== null) {
            return LicenseVerdict::refused($remembered);
        }

        try {
            $raw = $this->client->fetch($licenseKey, $version);
        } catch (LicenseServiceUnavailable $exception) {
            $this->logger->warning('The licensing service could not be reached: ' . $exception->getMessage());

            return $this->rememberFailure(LicenseState::Unreachable);
        }

        $fresh = $this->evaluator->usableToken($raw, $version, $now);
        if ($fresh === null) {
            if ($this->evaluator->hasBrokenSignature($raw)) {
                $this->logger->error('The signature of the license artefact could not be verified against any accepted signing key. Either the artefact was tampered with, or the licensing service signs with a key this plugin does not accept.');

                return $this->rememberFailure(LicenseState::SignatureInvalid);
            }

            $this->logger->error('The licensing service answered with an artefact this installation cannot use.');

            return $this->rememberFailure(LicenseState::Unreachable);
        }

        $this->store->store($licenseKey, $raw);

        return $this->evaluator->verdictFor($fresh);
    }

    private function rememberedFailure(): ?LicenseState
    {
        /** @var mixed $remembered */
        $remembered = $this->cache->get(self::FAILURE_MEMORY_KEY, static function (ItemInterface $item): ?LicenseState {
            // Nothing is remembered, so nothing failed. The entry expires straight away rather
            // than leaving a permanent negative answer behind.
            $item->expiresAfter(1);

            return null;
        });

        return $remembered instanceof LicenseState ? $remembered : null;
    }

    private function rememberFailure(LicenseState $state): LicenseVerdict
    {
        $this->cache->delete(self::FAILURE_MEMORY_KEY);
        $this->cache->get(self::FAILURE_MEMORY_KEY, static function (ItemInterface $item) use ($state): LicenseState {
            $item->expiresAfter(self::FAILURE_MEMORY_SECONDS);

            return $state;
        });

        return LicenseVerdict::refused($state);
    }
}
