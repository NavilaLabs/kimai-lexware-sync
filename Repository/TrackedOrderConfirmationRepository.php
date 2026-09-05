<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use App\Repository\Paginator\QueryPaginator;
use App\Utils\Pagination;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\DocumentStatusFilter;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationStatus;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\Query\DocumentListQuery;

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

    public function findPage(DocumentListQuery $listQuery): Pagination
    {
        $queryBuilder = $this->createFilteredQueryBuilder($listQuery)
            ->orderBy('orderConfirmation.voucherDate', 'DESC')
            ->addOrderBy('orderConfirmation.id', 'DESC');

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
            ->select('COUNT(orderConfirmation.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return max(0, $count);
    }

    private function createFilteredQueryBuilder(DocumentListQuery $listQuery): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('orderConfirmation')
            ->where('orderConfirmation.status IN (:statuses)')
            ->setParameter('statuses', $this->statusValuesFor($listQuery->status));

        $searchPattern = $listQuery->getSearchPattern();
        if ($searchPattern !== null) {
            $queryBuilder
                ->andWhere($queryBuilder->expr()->orX(
                    'orderConfirmation.voucherNumber LIKE :searchPattern',
                    'orderConfirmation.title LIKE :searchPattern',
                    'orderConfirmation.contactName LIKE :searchPattern',
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
            DocumentStatusFilter::Open => [OrderConfirmationStatus::Pending],
            DocumentStatusFilter::Converted => [OrderConfirmationStatus::AutomaticallyConverted, OrderConfirmationStatus::ManuallyConverted],
            DocumentStatusFilter::Rejected => [OrderConfirmationStatus::Rejected],
            DocumentStatusFilter::All => OrderConfirmationStatus::cases(),
        };

        return array_map(static fn (OrderConfirmationStatus $status): string => $status->value, $statuses);
    }

    public function save(TrackedOrderConfirmation $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
