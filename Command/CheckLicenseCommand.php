<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseEvaluator;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseServiceUnavailable;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\PluginVersion;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kimai:lexware-sync:check-license', description: 'Confirm the configured license with the licensing service')]
final class CheckLicenseCommand extends Command
{
    public function __construct(
        private readonly LexwareSyncConfiguration $configuration,
        private readonly LicenseStore $store,
        private readonly LicenseEvaluator $evaluator,
        private readonly LicenseClient $client,
        private readonly PluginVersion $pluginVersion,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $licenseKey = $this->configuration->getLicenseKey();
        if ($licenseKey === '') {
            $io->error('No license key is configured, so nothing can be confirmed.');

            return Command::FAILURE;
        }

        $version = $this->pluginVersion->current();
        $now = new \DateTimeImmutable();
        $stored = $this->evaluator->usableToken($this->store->storedToken($licenseKey), $version, $now);

        if ($stored !== null) {
            $recheckAfter = $stored->recheckAfter();
            if ($recheckAfter !== null && $recheckAfter > $now) {
                $io->success('The stored license is still current, nothing to do.');

                return $stored->isLicensed() ? Command::SUCCESS : Command::FAILURE;
            }
        }

        try {
            $raw = $this->client->fetch($licenseKey, $version);
        } catch (LicenseServiceUnavailable $exception) {
            $message = 'The licensing service could not be reached: ' . $exception->getMessage();
            $this->logger->error($message);
            $io->error($message);

            return Command::FAILURE;
        }

        $fresh = $this->evaluator->usableToken($raw, $version, $now);
        if ($fresh === null) {
            $message = 'The licensing service answered with an artefact this installation cannot use.';
            $this->logger->error($message);
            $io->error($message);

            return Command::FAILURE;
        }

        $this->store->store($licenseKey, $raw);

        if (!$fresh->isLicensed()) {
            $io->error('The license was refused: ' . ($fresh->reason() ?? 'no reason given'));

            return Command::FAILURE;
        }

        $io->success('The license is confirmed for ' . $fresh->customer() . '.');

        return Command::SUCCESS;
    }
}
