<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

use KimaiPlugin\KimaiLexwareSyncBundle\Client\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationSynchronizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kimai:lexware-sync:reconcile', description: 'Poll Lexware for order confirmations that a webhook delivery might have missed')]
final class ReconcileOrderConfirmationsCommand extends Command
{
    public function __construct(
        private readonly LexwareApiClient $client,
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly OrderConfirmationSynchronizer $synchronizer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $page = 0;
        $synchronized = 0;
        $failed = 0;
        $isLastPage = false;

        while (!$isLastPage) {
            $result = new LexwarePayload($this->client->listOrderConfirmationVoucherPage($page));

            foreach ($result->nestedList('content') as $voucher) {
                $lexwareId = $voucher->string('id');
                if ($lexwareId === '') {
                    continue;
                }

                if (!$this->needsSynchronization($lexwareId, $voucher)) {
                    continue;
                }

                try {
                    $this->synchronizer->synchronize($lexwareId);
                    $synchronized++;
                } catch (\Throwable $exception) {
                    $message = \sprintf('Failed to synchronize order confirmation %s: %s', $lexwareId, $exception->getMessage());
                    $this->logger->error($message);
                    $io->error($message);
                    $failed++;
                }
            }

            $isLastPage = $result->boolean('last', true);
            $page++;
        }

        $io->success(\sprintf('Reconciled %d order confirmation(s), %d failed.', $synchronized, $failed));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function needsSynchronization(string $lexwareId, LexwarePayload $voucher): bool
    {
        $existing = $this->repository->findByLexwareId($lexwareId);
        if ($existing === null) {
            return true;
        }

        $updatedDate = $voucher->dateTime('updatedDate');
        if ($updatedDate === null) {
            return true;
        }

        $remoteUpdatedAt = $existing->getRemoteUpdatedAt();
        if ($remoteUpdatedAt === null) {
            return true;
        }

        return $remoteUpdatedAt < $updatedDate;
    }
}
