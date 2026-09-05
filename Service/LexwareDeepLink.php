<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

final class LexwareDeepLink
{
    private const VOUCHER_LIST_URL = 'https://app.lexware.de/vouchers#!/VoucherList/?filter=%s&sort=sortByVoucherDate&sortDirection=desc&query=%s';

    public function forOrderConfirmation(string $voucherNumber): string
    {
        return $this->buildVoucherListUrl('orderconfirmation', $voucherNumber);
    }

    public function forInvoice(string $voucherNumber): string
    {
        return $this->buildVoucherListUrl('invoice', $voucherNumber);
    }

    private function buildVoucherListUrl(string $filter, string $voucherNumber): string
    {
        return \sprintf(self::VOUCHER_LIST_URL, $filter, rawurlencode($voucherNumber));
    }
}
