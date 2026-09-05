<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceStatus;

/**
 * @extends ServiceEntityRepository<TrackedInvoice>
 */
final class TrackedInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackedInvoice::class);
    }

    public function findByLexwareId(string $lexwareId): ?TrackedInvoice
    {
        return $this->findOneBy(['lexwareId' => $lexwareId]);
    }

    /**
     * @return TrackedInvoice[]
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => InvoiceStatus::Pending], ['voucherDate' => 'DESC']);
    }

    public function countPending(): int
    {
        return $this->count(['status' => InvoiceStatus::Pending]);
    }

    /**
     * @return TrackedInvoice[]
     */
    public function findRecentlyConverted(int $limit = 20): array
    {
        return $this->findBy(['status' => InvoiceStatus::Converted], ['processedAt' => 'DESC'], $limit);
    }

    public function save(TrackedInvoice $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
