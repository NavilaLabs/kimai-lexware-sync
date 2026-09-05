<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceStatus;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;

#[ORM\Entity(repositoryClass: TrackedInvoiceRepository::class)]
#[ORM\Table(name: 'kimai2_ext_lexware_invoice')]
class TrackedInvoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'lexware_id', type: 'string', length: 100, unique: true)]
    private string $lexwareId;

    #[ORM\Column(name: 'voucher_number', type: 'string', length: 100)]
    private string $voucherNumber = '';

    #[ORM\Column(name: 'voucher_date', type: 'datetime_immutable')]
    private \DateTimeImmutable $voucherDate;

    #[ORM\Column(name: 'raw_payload', type: 'text')]
    private string $rawPayload = '{}';

    #[ORM\ManyToOne(targetEntity: TrackedOrderConfirmation::class)]
    #[ORM\JoinColumn(name: 'related_order_confirmation_id', nullable: false)]
    private TrackedOrderConfirmation $relatedOrderConfirmation;

    #[ORM\Column(name: 'status', type: 'string', length: 30, enumType: InvoiceStatus::class)]
    private InvoiceStatus $status;

    #[ORM\Column(name: 'creation_attempted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $creationAttemptedAt = null;

    #[ORM\Column(name: 'created_invoice_lexware_id', type: 'string', length: 100, nullable: true)]
    private ?string $createdInvoiceLexwareId = null;

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

    public function __construct(string $lexwareId, TrackedOrderConfirmation $relatedOrderConfirmation)
    {
        $this->lexwareId = $lexwareId;
        $this->relatedOrderConfirmation = $relatedOrderConfirmation;
        $this->status = InvoiceStatus::Pending;
        $this->voucherDate = new \DateTimeImmutable();
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSynchronizedAt = new \DateTimeImmutable();
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

    public function getVoucherDate(): \DateTimeImmutable
    {
        return $this->voucherDate;
    }

    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }

    public function getRelatedOrderConfirmation(): TrackedOrderConfirmation
    {
        return $this->relatedOrderConfirmation;
    }

    public function getStatus(): InvoiceStatus
    {
        return $this->status;
    }

    public function getCreationAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->creationAttemptedAt;
    }

    public function getCreatedInvoiceLexwareId(): ?string
    {
        return $this->createdInvoiceLexwareId;
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

    public function updateFromLexwarePayload(
        string $voucherNumber,
        \DateTimeImmutable $voucherDate,
        string $rawPayload,
        ?\DateTimeImmutable $remoteUpdatedAt
    ): void {
        $this->voucherNumber = $voucherNumber;
        $this->voucherDate = $voucherDate;
        $this->rawPayload = $rawPayload;
        $this->lastSynchronizedAt = new \DateTimeImmutable();
        $this->remoteUpdatedAt = $remoteUpdatedAt;
    }

    public function markCreationAttempted(): void
    {
        $this->creationAttemptedAt = new \DateTimeImmutable();
    }

    public function clearCreationAttempt(): void
    {
        $this->creationAttemptedAt = null;
    }

    public function markConverted(string $createdInvoiceLexwareId, User $processedBy): void
    {
        $this->status = InvoiceStatus::Converted;
        $this->createdInvoiceLexwareId = $createdInvoiceLexwareId;
        $this->creationAttemptedAt = null;
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $processedBy;
    }

    public function markRejected(User $processedBy): void
    {
        $this->status = InvoiceStatus::Rejected;
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $processedBy;
        $this->creationAttemptedAt = null;
    }

    public function markSuperseded(): void
    {
        $this->status = InvoiceStatus::Superseded;
    }

    public function reopen(): void
    {
        $this->status = InvoiceStatus::Pending;
        $this->processedAt = null;
        $this->processedBy = null;
        $this->creationAttemptedAt = null;
    }
}
