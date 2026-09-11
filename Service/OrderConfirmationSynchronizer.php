<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\KimaiLexwareSyncBundle\Client\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Exception\CustomerCurrencyMismatchException;
use KimaiPlugin\KimaiLexwareSyncBundle\Exception\License\LicenseRequiredException;
use KimaiPlugin\KimaiLexwareSyncBundle\Exception\UnprocessableOrderConfirmationException;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseVerdictMessageFormatter;
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
        private readonly LicenseVerdictMessageFormatter $licenseVerdictMessageFormatter,
        private readonly ProjectChangeIndicator $projectChangeIndicator,
    ) {
    }

    public function synchronize(string $lexwareId): void
    {
        $payload = new LexwarePayload($this->client->getOrderConfirmation($lexwareId));
        $existing = $this->repository->findByLexwareId($lexwareId);

        $remoteUpdatedAt = $payload->dateTime('updatedDate');

        if ($existing !== null && $remoteUpdatedAt !== null && $existing->getRemoteUpdatedAt() !== null) {
            if ($remoteUpdatedAt <= $existing->getRemoteUpdatedAt()) {
                return;
            }
        }

        $this->entityManager->beginTransaction();

        try {
            $orderConfirmation = $existing ?? new TrackedOrderConfirmation($lexwareId);

            $address = $payload->nested('address');

            $orderConfirmation->updateFromLexwarePayload(
                $payload->string('voucherNumber'),
                $payload->string('title'),
                $payload->dateTime('voucherDate') ?? new \DateTimeImmutable(),
                $address->string('contactId'),
                $address->string('name'),
                json_encode($payload->toArray(), \JSON_THROW_ON_ERROR),
                $remoteUpdatedAt,
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
                } catch (LicenseRequiredException $exception) {
                    $this->logger->error(\sprintf(
                        'Order confirmation %s was not converted automatically: %s',
                        $lexwareId,
                        $this->licenseVerdictMessageFormatter->format($exception->verdict()),
                    ));
                } catch (CustomerCurrencyMismatchException | UnprocessableOrderConfirmationException $exception) {
                    $this->logger->error(\sprintf(
                        'Order confirmation %s was not converted automatically: %s',
                        $lexwareId,
                        $exception->getMessage(),
                    ));
                }
            }

            $this->projectChangeIndicator->synchronize($orderConfirmation);

            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
    }
}
