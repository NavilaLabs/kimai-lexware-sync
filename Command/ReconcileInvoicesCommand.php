<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

use KimaiPlugin\KimaiLexwareSyncBundle\Client\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\ContactMappingRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceSynchronizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[
    AsCommand(
        name: 'kimai:lexware-sync:reconcile-invoices',
        description: 'Poll Lexware for invoice drafts that a webhook delivery might have missed',
    ),
]
final class ReconcileInvoicesCommand extends Command
{
    public function __construct(
        private readonly LexwareApiClient $client,
        private readonly TrackedInvoiceRepository $repository,
        private readonly ContactMappingRepository $contactMappingRepository,
        private readonly InvoiceSynchronizer $synchronizer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $page = 0;
        $synchronized = 0;
        $failed = 0;
        $isLastPage = false;

        while (!$isLastPage) {
            $result = new LexwarePayload($this->client->listInvoiceVoucherPage($page));

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
                    $message = \sprintf(
                        'Failed to synchronize invoice %s: %s',
                        $lexwareId,
                        $exception->getMessage(),
                    );
                    $this->logger->error($message);
                    $io->error($message);
                    $failed++;
                }
            }

            $isLastPage = $result->boolean('last', true);
            $page++;
        }

        $io->success(
            \sprintf(
                'Reconciled %d invoice(s), %d failed.',
                $synchronized,
                $failed,
            ),
        );

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function needsSynchronization(
        string $lexwareId,
        LexwarePayload $voucher,
    ): bool {
        $existing = $this->repository->findByLexwareId($lexwareId);

        if ($existing === null) {
            $contactId = $voucher->string('contactId');
            if (
                $contactId !== '' &&
                $this->contactMappingRepository->findByLexwareContactId(
                    $contactId,
                ) === null
            ) {
                return false;
            }

            return true;
        }

        if ($existing->getStatus()->isTerminal()) {
            return false;
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
