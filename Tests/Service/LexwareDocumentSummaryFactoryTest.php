<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareDocumentSummaryFactory;
use PHPUnit\Framework\TestCase;

final class LexwareDocumentSummaryFactoryTest extends TestCase
{
    public function testSummaryIsReadFromACompletePayload(): void
    {
        $payload = json_encode([
            'address' => ['name' => 'test kunde'],
            'totalPrice' => ['currency' => 'EUR', 'totalNetAmount' => 1200],
            'lineItems' => [['name' => 'Balkon'], ['name' => 'Montage']],
            'files' => ['documentFileId' => '70229246-569f-4f0e-a137-8ea85e8527ed'],
        ], \JSON_THROW_ON_ERROR);

        $summary = (new LexwareDocumentSummaryFactory())->fromRawPayload($payload);

        self::assertSame('test kunde', $summary->contactName);
        self::assertSame(1200.0, $summary->totalNetAmount);
        self::assertSame('EUR', $summary->currency);
        self::assertSame(2, $summary->lineItemCount);
        self::assertTrue($summary->hasDocumentFile);
    }

    public function testInvoiceDraftWithoutAFileHasNoPdf(): void
    {
        $payload = json_encode([
            'address' => ['name' => 'test kunde'],
            'totalPrice' => ['currency' => 'EUR', 'totalNetAmount' => 150],
            'lineItems' => [],
            'files' => null,
        ], \JSON_THROW_ON_ERROR);

        $summary = (new LexwareDocumentSummaryFactory())->fromRawPayload($payload);

        self::assertFalse($summary->hasDocumentFile);
        self::assertSame(0, $summary->lineItemCount);
    }

    public function testUnusablePayloadFallsBackToEmptyValues(): void
    {
        $summary = (new LexwareDocumentSummaryFactory())->fromRawPayload('not json at all');

        self::assertSame('', $summary->contactName);
        self::assertSame(0.0, $summary->totalNetAmount);
        self::assertSame('EUR', $summary->currency);
        self::assertSame(0, $summary->lineItemCount);
        self::assertFalse($summary->hasDocumentFile);
    }
}
