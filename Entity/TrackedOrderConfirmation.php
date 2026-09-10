<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationStatus;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;

#[ORM\Entity(repositoryClass: TrackedOrderConfirmationRepository::class)]
#[ORM\Table(name: 'kimai2_ext_lexware_order_confirmation')]
class TrackedOrderConfirmation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'lexware_id', type: 'string', length: 100, unique: true)]
    private string $lexwareId;

    #[ORM\Column(name: 'voucher_number', type: 'string', length: 100)]
    private string $voucherNumber = '';

    #[ORM\Column(name: 'title', type: 'string', length: 255)]
    private string $title = '';

    #[ORM\Column(name: 'voucher_date', type: 'datetime_immutable')]
    private \DateTimeImmutable $voucherDate;

    #[ORM\Column(name: 'lexware_contact_id', type: 'string', length: 100)]
    private string $lexwareContactId = '';

    #[ORM\Column(name: 'contact_name', type: 'string', length: 255)]
    private string $contactName = '';

    #[ORM\Column(name: 'raw_payload', type: 'text')]
    private string $rawPayload = '{}';

    #[ORM\Column(name: 'status', type: 'string', length: 30, enumType: OrderConfirmationStatus::class)]
    private OrderConfirmationStatus $status;

    #[ORM\Column(name: 'changed_after_conversion', type: 'boolean')]
    private bool $changedAfterConversion = false;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\Column(name: 'first_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_synchronized_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSynchronizedAt;

    #[ORM\Column(name: 'remote_updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $remoteUpdatedAt = null;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'processed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $processedBy = null;

    /**
     * @var Collection<int, TrackedOrderConfirmationLine>
     */
    #[ORM\OneToMany(targetEntity: TrackedOrderConfirmationLine::class, mappedBy: 'orderConfirmation', cascade: ['persist', 'remove'])]
    private Collection $lines;

    public function __construct(string $lexwareId)
    {
        $this->lexwareId = $lexwareId;
        $this->status = OrderConfirmationStatus::Pending;
        $this->voucherDate = new \DateTimeImmutable();
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSynchronizedAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLexwareId(): string
    {
        return $this->lexwareId;
    }

    public function getVoucherNumber(): string
    {
        return $this->voucherNumber;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getVoucherDate(): \DateTimeImmutable
    {
        return $this->voucherDate;
    }

    public function getLexwareContactId(): string
    {
        return $this->lexwareContactId;
    }

    public function getContactName(): string
    {
        return $this->contactName;
    }

    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }

    public function getStatus(): OrderConfirmationStatus
    {
        return $this->status;
    }

    public function hasChangedAfterConversion(): bool
    {
        return $this->changedAfterConversion;
    }

    public function clearChangedAfterConversion(): void
    {
        $this->changedAfterConversion = false;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): void
    {
        $this->project = $project;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): void
    {
        $this->customer = $customer;
    }

    public function getLastSynchronizedAt(): \DateTimeImmutable
    {
        return $this->lastSynchronizedAt;
    }

    public function getRemoteUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->remoteUpdatedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getProcessedBy(): ?User
    {
        return $this->processedBy;
    }

    /**
     * @return Collection<int, TrackedOrderConfirmationLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(TrackedOrderConfirmationLine $line): void
    {
        $this->lines->add($line);
    }

    public function updateFromLexwarePayload(
        string $voucherNumber,
        string $title,
        \DateTimeImmutable $voucherDate,
        string $lexwareContactId,
        string $contactName,
        string $rawPayload,
        ?\DateTimeImmutable $remoteUpdatedAt
    ): void {
        if ($this->status->isConverted() && $this->rawPayload !== $rawPayload) {
            $this->changedAfterConversion = true;
        }

        $this->voucherNumber = $voucherNumber;
        $this->title = $title;
        $this->voucherDate = $voucherDate;
        $this->lexwareContactId = $lexwareContactId;
        $this->contactName = $contactName;
        $this->rawPayload = $rawPayload;
        $this->lastSynchronizedAt = new \DateTimeImmutable();
        $this->remoteUpdatedAt = $remoteUpdatedAt;
    }

    public function markAutomaticallyConverted(): void
    {
        $this->status = OrderConfirmationStatus::AutomaticallyConverted;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markManuallyConverted(User $processedBy): void
    {
        $this->status = OrderConfirmationStatus::ManuallyConverted;
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $processedBy;
    }

    public function markRejected(User $processedBy): void
    {
        $this->status = OrderConfirmationStatus::Rejected;
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $processedBy;
    }
}
