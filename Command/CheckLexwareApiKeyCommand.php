<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kimai:lexware-sync:check-api-key', description: 'Confirm the configured Lexware API key still authenticates')]
final class CheckLexwareApiKeyCommand extends Command
{
    public function __construct(
        private readonly LexwareApiClient $client,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->client->listOrderConfirmationVoucherPage(0);
        } catch (LexwareApiException $exception) {
            $message = 'The Lexware API key no longer authenticates: ' . $exception->getMessage();
            $this->logger->error($message);
            $io->error($message);

            return Command::FAILURE;
        }

        $io->success('The Lexware API key still authenticates.');

        return Command::SUCCESS;
    }
}
