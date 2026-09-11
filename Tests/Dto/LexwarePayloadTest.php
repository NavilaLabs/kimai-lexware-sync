<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Dto;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use PHPUnit\Framework\TestCase;

final class LexwarePayloadTest extends TestCase
{
    public function testAMissingFieldFallsBackToTheGivenDefault(): void
    {
        $payload = new LexwarePayload([]);

        self::assertSame('', $payload->string('title'));
        self::assertSame('unknown', $payload->string('title', 'unknown'));
        self::assertSame(0, $payload->integer('taxRatePercentage'));
        self::assertSame(0.0, $payload->float('netAmount'));
        self::assertTrue($payload->boolean('last', true));
        self::assertNull($payload->nullableString('title'));
        self::assertNull($payload->dateTime('voucherDate'));
    }

    public function testAFieldOfTheWrongTypeFallsBackInsteadOfBeingForced(): void
    {
        $payload = new LexwarePayload(['title' => ['unexpected'], 'netAmount' => 'not a number']);

        self::assertSame('fallback', $payload->string('title', 'fallback'));
        self::assertSame(1.0, $payload->float('netAmount', 1.0));
    }

    public function testNumbersArrivingAsStringsAreStillRead(): void
    {
        $payload = new LexwarePayload(['netAmount' => '12.5', 'taxRatePercentage' => '19']);

        self::assertSame(12.5, $payload->float('netAmount'));
        self::assertSame(19, $payload->integer('taxRatePercentage'));
    }

    public function testNestedObjectsAndListsAreReadAsPayloadsThemselves(): void
    {
        $payload = new LexwarePayload([
            'address' => ['contactId' => 'contact-1', 'name' => 'Contact GmbH'],
            'lineItems' => [
                ['name' => 'Development'],
                'not an object',
                ['name' => 'Consulting'],
            ],
        ]);

        self::assertSame('contact-1', $payload->nested('address')->string('contactId'));
        self::assertSame('', $payload->nested('missing')->string('anything'));

        $lines = $payload->nestedList('lineItems');
        self::assertCount(2, $lines, 'An entry that is not an object is skipped rather than breaking the whole list.');
        self::assertSame('Development', $lines[0]->string('name'));
        self::assertSame([['name' => 'Development'], ['name' => 'Consulting']], $payload->rawList('lineItems'));
    }

    public function testAnUnparseableDateIsNullRatherThanAnException(): void
    {
        self::assertNull((new LexwarePayload(['voucherDate' => 'yesterday-ish']))->dateTime('voucherDate'));
        self::assertEquals(
            new \DateTimeImmutable('2026-09-01T00:00:00+02:00'),
            (new LexwarePayload(['voucherDate' => '2026-09-01T00:00:00.000+02:00']))->dateTime('voucherDate')
        );
    }

    public function testInvalidJsonBecomesAnEmptyPayload(): void
    {
        self::assertSame([], LexwarePayload::fromJson('{not json')->toArray());
        self::assertSame('abc', LexwarePayload::fromJson('{"id":"abc"}')->string('id'));
    }
}
