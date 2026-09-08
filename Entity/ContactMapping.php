<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\Customer;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\ContactMappingRepository;

#[ORM\Entity(repositoryClass: ContactMappingRepository::class)]
#[ORM\Table(name: 'kimai2_ext_lexware_contact_mapping')]
class ContactMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'lexware_contact_id', type: 'string', length: 100, unique: true)]
    private string $lexwareContactId;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $lexwareContactId, Customer $customer)
    {
        $this->lexwareContactId = $lexwareContactId;
        $this->customer = $customer;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getLexwareContactId(): string
    {
        return $this->lexwareContactId;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }
}
