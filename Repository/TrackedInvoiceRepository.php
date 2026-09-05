<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use App\Repository\Paginator\QueryPaginator;
use App\Utils\Pagination;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\DocumentStatusFilter;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceStatus;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\Query\DocumentListQuery;

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

    public function findPage(DocumentListQuery $listQuery): Pagination
    {
        $queryBuilder = $this->createFilteredQueryBuilder($listQuery)
            ->orderBy('trackedInvoice.voucherDate', 'DESC')
            ->addOrderBy('trackedInvoice.id', 'DESC');

        $pagination = new Pagination(new QueryPaginator($queryBuilder->getQuery(), $this->countByListQuery($listQuery)));
        $pagination->setMaxPerPage(DocumentListQuery::PAGE_SIZE);
        $pagination->setCurrentPage($listQuery->page);

        return $pagination;
    }

    /**
     * @return int<0, max>
     */
    public function countByListQuery(DocumentListQuery $listQuery): int
    {
        $count = (int) $this->createFilteredQueryBuilder($listQuery)
            ->select('COUNT(trackedInvoice.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return max(0, $count);
    }

    private function createFilteredQueryBuilder(DocumentListQuery $listQuery): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('trackedInvoice')
            ->where('trackedInvoice.status IN (:statuses)')
            ->setParameter('statuses', $this->statusValuesFor($listQuery->status));

        $searchPattern = $listQuery->getSearchPattern();
        if ($searchPattern !== null) {
            $queryBuilder
                ->andWhere($queryBuilder->expr()->orX(
                    'trackedInvoice.voucherNumber LIKE :searchPattern',
                    'trackedInvoice.contactName LIKE :searchPattern',
                ))
                ->setParameter('searchPattern', $searchPattern);
        }

        return $queryBuilder;
    }

    /**
     * @return string[]
     */
    private function statusValuesFor(DocumentStatusFilter $filter): array
    {
        $statuses = match ($filter) {
            DocumentStatusFilter::Open => [InvoiceStatus::Pending],
            DocumentStatusFilter::Converted => [InvoiceStatus::Converted],
            DocumentStatusFilter::Rejected => [InvoiceStatus::Rejected],
            DocumentStatusFilter::All => InvoiceStatus::cases(),
        };

        return array_map(static fn (InvoiceStatus $status): string => $status->value, $statuses);
    }

    public function save(TrackedInvoice $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
