<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationSynchronizer;
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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $page = 0;
        $synchronized = 0;
        $isLastPage = false;

        while (!$isLastPage) {
            $result = $this->client->listOrderConfirmationVoucherPage($page);
            $content = $result['content'] ?? [];

            foreach ($content as $voucher) {
                $lexwareId = (string) ($voucher['id'] ?? '');
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
                    $io->error(\sprintf('Failed to synchronize order confirmation %s: %s', $lexwareId, $exception->getMessage()));
                }
            }

            $isLastPage = (bool) ($result['last'] ?? true);
            $page++;
        }

        $io->success(\sprintf('Reconciled %d order confirmation(s).', $synchronized));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $voucher
     */
    private function needsSynchronization(string $lexwareId, array $voucher): bool
    {
        $existing = $this->repository->findByLexwareId($lexwareId);
        if ($existing === null) {
            return true;
        }

        $updatedDate = $voucher['updatedDate'] ?? null;
        if ($updatedDate === null) {
            return true;
        }

        return $existing->getLastSynchronizedAt() < new \DateTimeImmutable((string) $updatedDate);
    }
}
