<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Contract;

final class ReadingContractTest extends LexwareContractTestCase
{
    public function testTheVoucherListStillCarriesTheFieldsTheReconciliationPollReads(): void
    {
        $page = $this->client()->listOrderConfirmationVoucherPage(0);

        $this->assertFieldTypes($page, ['last' => 'bool'], 'voucher list');
        $content = $this->nestedList($page, 'content', 'voucher list');

        if ($content === []) {
            self::markTestSkipped('The test account holds no order confirmations to inspect.');
        }

        $this->assertFieldTypes($content[0], [
            'id' => 'string',
            'voucherNumber' => 'string',
            'updatedDate' => 'string',
        ], 'voucher list entry');
    }

    public function testAnOrderConfirmationStillCarriesTheFieldsTheProcessorReads(): void
    {
        $orderConfirmation = $this->anOrderConfirmation();

        $this->assertFieldTypes($orderConfirmation, [
            'id' => 'string',
            'voucherNumber' => 'string',
            'voucherDate' => 'string',
            'address.name' => 'string',
            'totalPrice.totalNetAmount' => 'float',
            'totalPrice.currency' => 'string',
        ], 'order confirmation');

        $lineItems = $this->nestedList($orderConfirmation, 'lineItems', 'order confirmation');
        self::assertNotSame([], $lineItems, 'An order confirmation without line items cannot verify the line mapping.');

        $this->assertFieldTypes($lineItems[0], [
            'type' => 'string',
            'name' => 'string',
        ], 'order confirmation line item');
    }

    public function testTheEventSubscriptionListStillCarriesTheFieldsTheConnectorReads(): void
    {
        // The client already unwraps whatever envelope Lexware uses, so this asserts on what the
        // connector actually receives: a plain list of subscriptions.
        $subscriptions = $this->client()->listEventSubscriptions();
        self::assertIsList($subscriptions);

        if ($subscriptions === []) {
            self::markTestSkipped('The test account has no event subscriptions registered.');
        }

        foreach ($subscriptions as $subscription) {
            $this->assertFieldTypes($subscription, [
                'subscriptionId' => 'string',
                'eventType' => 'string',
                'callbackUrl' => 'string',
            ], 'event subscription');
        }
    }
}
