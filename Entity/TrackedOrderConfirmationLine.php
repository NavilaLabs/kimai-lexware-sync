<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\Activity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'kimai2_ext_lexware_order_confirmation_line')]
final class TrackedOrderConfirmationLine
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

    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: true, onDelete: 'SET NULL')]
    private ?Activity $activity = null;

    public function __construct(
        TrackedOrderConfirmation $orderConfirmation,
        int $position,
        string $type,
        string $name,
        ?string $description,
        bool $matched
    ) {
        $this->orderConfirmation = $orderConfirmation;
        $this->position = $position;
        $this->type = $type;
        $this->name = $name;
        $this->description = $description;
        $this->matched = $matched;
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

    public function getActivity(): ?Activity
    {
        return $this->activity;
    }

    public function setActivity(?Activity $activity): void
    {
        $this->activity = $activity;
    }
}
