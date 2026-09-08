<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Contract;

/**
 * Lexware offers no endpoint to delete an invoice, so every run of this test leaves one draft
 * behind in the test account. That is the price of covering the write path, and it is the
 * reason this suite runs weekly rather than on every push.
 */
final class WritingContractTest extends LexwareContractTestCase
{
    public function testCreatingAnInvoiceDraftStillWorksAndStillIgnoresRelatedVouchers(): void
    {
        $source = $this->anOrderConfirmation();
        $sourceId = $this->stringField($source, 'id', 'order confirmation');

        $created = $this->client()->createInvoice($this->invoiceRequest($source) + [
            // Deliberately supplied: a spike on 2026-09-05 found that Lexware silently drops
            // this, and milestone two depends on that staying true. If Lexware ever starts
            // honouring it, this test says so, and the design gets simpler.
            'relatedVouchers' => [['id' => $sourceId, 'voucherType' => 'orderconfirmation']],
        ], false);

        $this->assertFieldTypes($created, ['id' => 'string', 'resourceUri' => 'string'], 'created invoice');

        $fetched = $this->client()->getInvoice($this->stringField($created, 'id', 'created invoice'));

        self::assertSame([], $fetched['relatedVouchers'] ?? [], 'Lexware now keeps a supplied relatedVouchers entry. Milestone two assumes it does not.');
        self::assertSame('draft', $fetched['voucherStatus'] ?? null);
    }

    public function testShippingConditionsAreStillMandatory(): void
    {
        $request = $this->invoiceRequest($this->anOrderConfirmation());
        unset($request['shippingConditions']);

        // Verified against the real API on 2026-09-05: status 406, "The shipping conditions must
        // not be null." Milestone two always sends them because of this.
        $this->expectExceptionMessageMatches('/shipping conditions must not be null/i');

        $this->client()->createInvoice($request, false);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function invoiceRequest(array $source): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP');

        return [
            'voucherDate' => $now,
            'address' => $source['address'] ?? [],
            'title' => $this->marker(),
            'lineItems' => [[
                'type' => 'custom',
                'name' => 'Contract test line',
                'quantity' => 1,
                'unitName' => 'hour',
                'unitPrice' => ['currency' => 'EUR', 'netAmount' => 1.0, 'taxRatePercentage' => 19],
            ]],
            'taxConditions' => ['taxType' => 'net'],
            'shippingConditions' => ['shippingType' => 'service', 'shippingDate' => $now],
            'totalPrice' => ['currency' => 'EUR'],
        ];
    }
}
