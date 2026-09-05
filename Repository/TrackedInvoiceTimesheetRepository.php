<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoiceTimesheet;

/**
 * @extends ServiceEntityRepository<TrackedInvoiceTimesheet>
 */
final class TrackedInvoiceTimesheetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackedInvoiceTimesheet::class);
    }

    public function save(TrackedInvoiceTimesheet $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }

    public function hasModifiedTimesheets(TrackedInvoice $trackedInvoice): bool
    {
        foreach ($this->findBy(['trackedInvoice' => $trackedInvoice]) as $link) {
            if ($link->wasModifiedAfterExport()) {
                return true;
            }
        }

        return false;
    }
}
