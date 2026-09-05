<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class LicenseToken
{
    /**
     * @param array<string, mixed> $body
     */
    private function __construct(
        private readonly string $raw,
        public readonly string $signedContent,
        public readonly string $signature,
        private readonly array $body,
    ) {
    }

    public static function parse(string $raw): self
    {
        $parts = explode('.', $raw);
        if (\count($parts) !== 2) {
            throw new MalformedLicenseToken('A license token consists of exactly two parts separated by a dot.');
        }

        $decodedBody = self::decode($parts[0]);
        $signature = self::decode($parts[1]);

        $body = json_decode($decodedBody, true);
        if (!\is_array($body) || array_is_list($body)) {
            throw new MalformedLicenseToken('The body of a license token must be a JSON object.');
        }

        $fields = [];
        foreach ($body as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return new self($raw, $parts[0], $signature, $fields);
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function isLicensed(): bool
    {
        return ($this->body['licensed'] ?? false) === true;
    }

    public function customer(): string
    {
        $value = $this->body['customer'] ?? null;

        return \is_string($value) ? $value : '';
    }

    public function version(): string
    {
        $value = $this->body['version'] ?? null;

        return \is_string($value) ? $value : '';
    }

    public function issuedAt(): ?\DateTimeImmutable
    {
        return $this->dateTime('issued_at');
    }

    public function recheckAfter(): ?\DateTimeImmutable
    {
        return $this->dateTime('recheck_after');
    }

    public function validUntil(): ?\DateTimeImmutable
    {
        return $this->dateTime('valid_until');
    }

    public function reason(): ?string
    {
        $value = $this->body['reason'] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function dateTime(string $field): ?\DateTimeImmutable
    {
        $value = $this->body[$field] ?? null;
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new MalformedLicenseToken('A part of the license token is not valid base64url.');
        }

        return $decoded;
    }
}
