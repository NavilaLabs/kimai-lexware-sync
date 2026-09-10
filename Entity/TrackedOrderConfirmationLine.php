<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\Activity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'kimai2_ext_lexware_order_confirmation_line')]
class TrackedOrderConfirmationLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrackedOrderConfirmation::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_confirmation_id', nullable: false, onDelete: 'CASCADE')]
    private TrackedOrderConfirmation $orderConfirmation;

    #[ORM\Column(name: 'position', type: 'integer')]
    private int $position;

    #[ORM\Column(name: 'type', type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(name: 'name', type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(name: 'matched', type: 'boolean')]
    private bool $matched;

    #[ORM\Column(name: 'quantity', type: 'float')]
    private float $quantity;

    #[ORM\Column(name: 'unit_name', type: 'string', length: 255)]
    private string $unitName;

    #[ORM\Column(name: 'net_amount', type: 'float')]
    private float $netAmount;

    #[ORM\Column(name: 'is_hour_line', type: 'boolean')]
    private bool $isHourLine;

    #[ORM\Column(name: 'removed_from_source', type: 'boolean')]
    private bool $removedFromSource = false;

    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: true, onDelete: 'SET NULL')]
    private ?Activity $activity = null;

    public function __construct(
        TrackedOrderConfirmation $orderConfirmation,
        int $position,
        string $type,
        string $name,
        ?string $description,
        bool $matched,
        float $quantity,
        string $unitName,
        float $netAmount,
        bool $isHourLine
    ) {
        $this->orderConfirmation = $orderConfirmation;
        $this->position = $position;
        $this->type = $type;
        $this->name = $name;
        $this->description = $description;
        $this->matched = $matched;
        $this->quantity = $quantity;
        $this->unitName = $unitName;
        $this->netAmount = $netAmount;
        $this->isHourLine = $isHourLine;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isMatched(): bool
    {
        return $this->matched;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getUnitName(): string
    {
        return $this->unitName;
    }

    public function getNetAmount(): float
    {
        return $this->netAmount;
    }

    public function isHourLine(): bool
    {
        return $this->isHourLine;
    }

    public function isRemovedFromSource(): bool
    {
        return $this->removedFromSource;
    }

    public function updateFromLexwareLine(string $name, ?string $description, float $quantity, string $unitName, float $netAmount, bool $isHourLine): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->quantity = $quantity;
        $this->unitName = $unitName;
        $this->netAmount = $netAmount;
        $this->isHourLine = $isHourLine;
        $this->removedFromSource = false;
    }

    public function markRemovedFromSource(): void
    {
        $this->removedFromSource = true;
    }

    public function getActivity(): ?Activity
    {
        return $this->activity;
    }

    public function setActivity(?Activity $activity): void
    {
        $this->activity = $activity;
    }
}
