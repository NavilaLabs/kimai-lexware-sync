<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareDeepLink;
use PHPUnit\Framework\TestCase;

final class LexwareDeepLinkTest extends TestCase
{
    public function testOrderConfirmationLinkFiltersTheVoucherList(): void
    {
        $deepLink = new LexwareDeepLink();

        self::assertSame(
            'https://app.lexware.de/vouchers#!/VoucherList/?filter=orderconfirmation&sort=sortByVoucherDate&sortDirection=desc&query=AB0003',
            $deepLink->forOrderConfirmation('AB0003'),
        );
    }

    public function testInvoiceLinkFiltersTheVoucherList(): void
    {
        $deepLink = new LexwareDeepLink();

        self::assertSame(
            'https://app.lexware.de/vouchers#!/VoucherList/?filter=invoice&sort=sortByVoucherDate&sortDirection=desc&query=RE0007',
            $deepLink->forInvoice('RE0007'),
        );
    }

    public function testVoucherNumberIsEncoded(): void
    {
        $deepLink = new LexwareDeepLink();

        self::assertStringEndsWith('query=RE%202026%2F7', $deepLink->forInvoice('RE 2026/7'));
    }
}
