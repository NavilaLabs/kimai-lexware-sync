<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\DocumentStatusFilter;
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
    public function findByStatusFilter(DocumentStatusFilter $filter): array
    {
        return $this->findBy(['status' => $this->statusesFor($filter)], ['voucherDate' => 'DESC']);
    }

    public function countByStatusFilter(DocumentStatusFilter $filter): int
    {
        return $this->count(['status' => $this->statusesFor($filter)]);
    }

    /**
     * @return InvoiceStatus[]
     */
    private function statusesFor(DocumentStatusFilter $filter): array
    {
        return match ($filter) {
            DocumentStatusFilter::Open => [InvoiceStatus::Pending],
            DocumentStatusFilter::Converted => [InvoiceStatus::Converted],
            DocumentStatusFilter::Rejected => [InvoiceStatus::Rejected],
            DocumentStatusFilter::All => InvoiceStatus::cases(),
        };
    }

    public function save(TrackedInvoice $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
