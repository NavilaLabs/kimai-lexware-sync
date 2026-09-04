<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use Psr\Log\LoggerInterface;

final class OrderConfirmationSynchronizer
{
    public function __construct(
        private readonly LexwareApiClient $client,
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly MatchingRuleEvaluator $matchingRuleEvaluator,
        private readonly OrderConfirmationProcessor $processor,
        private readonly LexwareSyncConfiguration $configuration,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function synchronize(string $lexwareId): void
    {
        $payload = $this->client->getOrderConfirmation($lexwareId);
        $existing = $this->repository->findByLexwareId($lexwareId);

        if ($existing !== null && isset($payload['updatedDate'])) {
            $remoteUpdatedAt = new \DateTimeImmutable((string) $payload['updatedDate']);
            if ($remoteUpdatedAt <= $existing->getLastSynchronizedAt()) {
                return;
            }
        }

        $this->entityManager->beginTransaction();

        try {
            $orderConfirmation = $existing ?? new TrackedOrderConfirmation($lexwareId);

            $address = $payload['address'] ?? [];

            $orderConfirmation->updateFromLexwarePayload(
                (string) ($payload['voucherNumber'] ?? ''),
                (string) ($payload['title'] ?? ''),
                new \DateTimeImmutable((string) ($payload['voucherDate'] ?? 'now')),
                (string) ($address['contactId'] ?? ''),
                json_encode($payload, \JSON_THROW_ON_ERROR),
            );

            $this->repository->save($orderConfirmation);

            if ($orderConfirmation->getStatus()->isPending()
                && $this->configuration->isAutoConvertEnabled()
                && $this->matchingRuleEvaluator->matchesTitle($orderConfirmation->getTitle(), $this->configuration->getTitleRegex())
            ) {
                try {
                    $this->processor->convert(
                        $orderConfirmation,
                        $payload,
                        null,
                        $this->configuration->getLineRegex(),
                        $this->configuration->isReadLinesEnabled(),
                    );
                    $this->repository->save($orderConfirmation);
                } catch (CustomerCurrencyMismatchException $exception) {
                    $this->logger->error(\sprintf(
                        'Order confirmation %s was not converted automatically: %s',
                        $lexwareId,
                        $exception->getMessage(),
                    ));
                }
            }

            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
    }
}
