<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

final class RecordedRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $url,
        private readonly mixed $body,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedBody(): array
    {
        if (!\is_string($this->body) || $this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
