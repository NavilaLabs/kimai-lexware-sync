<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\DocumentStatusFilter;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationStatus;

/**
 * @extends ServiceEntityRepository<TrackedOrderConfirmation>
 */
final class TrackedOrderConfirmationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackedOrderConfirmation::class);
    }

    public function findByLexwareId(string $lexwareId): ?TrackedOrderConfirmation
    {
        return $this->findOneBy(['lexwareId' => $lexwareId]);
    }

    /**
     * @return TrackedOrderConfirmation[]
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => OrderConfirmationStatus::Pending], ['voucherDate' => 'DESC']);
    }

    public function countPending(): int
    {
        return $this->count(['status' => OrderConfirmationStatus::Pending]);
    }

    /**
     * @return TrackedOrderConfirmation[]
     */
    public function findByStatusFilter(DocumentStatusFilter $filter): array
    {
        return $this->findBy(['status' => $this->statusesFor($filter)], ['voucherDate' => 'DESC']);
    }

    public function countByStatusFilter(DocumentStatusFilter $filter): int
    {
        return $this->count(['status' => $this->statusesFor($filter)]);
    }

    /**
     * @return OrderConfirmationStatus[]
     */
    private function statusesFor(DocumentStatusFilter $filter): array
    {
        return match ($filter) {
            DocumentStatusFilter::Open => [OrderConfirmationStatus::Pending],
            DocumentStatusFilter::Converted => [OrderConfirmationStatus::AutomaticallyConverted, OrderConfirmationStatus::ManuallyConverted],
            DocumentStatusFilter::Rejected => [OrderConfirmationStatus::Rejected],
            DocumentStatusFilter::All => OrderConfirmationStatus::cases(),
        };
    }

    public function save(TrackedOrderConfirmation $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
