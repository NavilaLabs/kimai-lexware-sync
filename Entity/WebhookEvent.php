<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'kimai2_ext_lexware_webhook_event')]
final class WebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'event_type', type: 'string', length: 100)]
    private string $eventType;

    #[ORM\Column(name: 'resource_id', type: 'string', length: 100, nullable: true)]
    private ?string $resourceId;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'signature_valid', type: 'boolean')]
    private bool $signatureValid;

    #[ORM\Column(name: 'processed', type: 'boolean')]
    private bool $processed = false;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function __construct(string $eventType, ?string $resourceId, bool $signatureValid)
    {
        $this->eventType = $eventType;
        $this->resourceId = $resourceId;
        $this->signatureValid = $signatureValid;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function markProcessed(): void
    {
        $this->processed = true;
    }

    public function markFailed(string $errorMessage): void
    {
        $this->processed = false;
        $this->errorMessage = $errorMessage;
    }
}
