<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Dto;

/**
 * A Lexware response is an untyped array all the way down. Reading it directly means casting
 * values whose type nothing guarantees, and a malformed response then fails somewhere far away
 * from the field that caused it. This reads one such array and answers with the type that was
 * asked for, falling back to a given default whenever the field is missing or of another type.
 */
final class LexwarePayload
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data = [])
    {
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        return new self(\is_array($decoded) ? $decoded : []);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;

        if (\is_string($value)) {
            return $value;
        }

        return \is_scalar($value) ? (string) $value : $default;
    }

    public function nullableString(string $key): ?string
    {
        return $this->has($key) && $this->data[$key] !== null ? $this->string($key) : null;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? null;

        return \is_bool($value) ? $value : (\is_scalar($value) ? (bool) $value : $default);
    }

    public function dateTime(string $key): ?\DateTimeImmutable
    {
        $value = $this->nullableString($key);
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function nested(string $key): self
    {
        $value = $this->data[$key] ?? null;

        return new self(\is_array($value) ? $value : []);
    }

    /**
     * @return list<self>
     */
    public function nestedList(string $key): array
    {
        return array_map(static fn (array $entry): self => new self($entry), $this->rawList($key));
    }

    /**
     * The entries as plain arrays, for the places that pass Lexware's own structures straight
     * back to Lexware without ever reading them.
     *
     * @return list<array<string, mixed>>
     */
    public function rawList(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $entries = [];
        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $fields = [];
            foreach ($entry as $name => $field) {
                $fields[(string) $name] = $field;
            }

            $entries[] = $fields;
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
