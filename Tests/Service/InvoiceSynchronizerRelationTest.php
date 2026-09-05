<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceSynchronizer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class InvoiceSynchronizerRelationTest extends TestCase
{
    public function testFirstMatchingRelatedVoucherWins(): void
    {
        $method = new ReflectionMethod(InvoiceSynchronizer::class, 'extractRelatedVoucherIds');
        $method->setAccessible(true);

        $payload = [
            'relatedVouchers' => [
                ['id' => 'unrelated-id'],
                ['id' => 'matching-id'],
            ],
        ];

        self::assertSame(['unrelated-id', 'matching-id'], $method->invoke(null, $payload));
    }

    public function testMissingRelatedVouchersProducesAnEmptyList(): void
    {
        $method = new ReflectionMethod(InvoiceSynchronizer::class, 'extractRelatedVoucherIds');
        $method->setAccessible(true);

        self::assertSame([], $method->invoke(null, []));
    }
}
