<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
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

    public function save(TrackedOrderConfirmation $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
