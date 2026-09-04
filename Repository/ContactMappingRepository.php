<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\ContactMapping;

/**
 * @extends ServiceEntityRepository<ContactMapping>
 */
final class ContactMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMapping::class);
    }

    public function findByLexwareContactId(string $lexwareContactId): ?ContactMapping
    {
        return $this->findOneBy(['lexwareContactId' => $lexwareContactId]);
    }

    public function save(ContactMapping $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
