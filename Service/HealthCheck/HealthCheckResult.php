<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck;

final class HealthCheckResult
{
    public function __construct(
        public readonly string $checkName,
        public readonly \DateTimeImmutable $checkedAt,
        public readonly bool $ok,
        public readonly ?string $message,
    ) {
    }

    public function isStale(int $intervalDays, \DateTimeImmutable $now): bool
    {
        return $this->checkedAt->modify('+' . $intervalDays . ' days') < $now;
    }

    public function toJson(): string
    {
        return json_encode([
            'checkName' => $this->checkName,
            'checkedAt' => $this->checkedAt->format(\DateTimeInterface::ATOM),
            'ok' => $this->ok,
            'message' => $this->message,
        ], \JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new \JsonException('A health check result must be a JSON object.');
        }

        $checkName = $decoded['checkName'] ?? null;
        $checkedAt = $decoded['checkedAt'] ?? null;
        $message = $decoded['message'] ?? null;

        return new self(
            \is_string($checkName) ? $checkName : '',
            new \DateTimeImmutable(\is_string($checkedAt) ? $checkedAt : 'now'),
            ($decoded['ok'] ?? false) === true,
            \is_string($message) ? $message : null,
        );
    }
}
