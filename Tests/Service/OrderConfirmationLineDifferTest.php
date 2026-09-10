<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationLineDiffStatus;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationLineDiffer;
use PHPUnit\Framework\TestCase;

final class OrderConfirmationLineDifferTest extends TestCase
{
    public function testAnUnchangedLineIsReportedAsUnchanged(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-1');
        $orderConfirmation->addLine(new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', null, true, 8.0, 'Stunden', 800.0, true));

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
        ]]);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, $payload);

        self::assertCount(1, $entries);
        self::assertSame(OrderConfirmationLineDiffStatus::Unchanged, $entries[0]->status);
    }

    public function testAChangedQuantityIsReportedAsChangedWithBothValues(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-2');
        $orderConfirmation->addLine(new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', null, true, 8.0, 'Stunden', 800.0, true));

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 12, 'unitName' => 'Stunden', 'lineItemAmount' => 1200.0],
        ]]);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, $payload);

        self::assertSame(OrderConfirmationLineDiffStatus::Changed, $entries[0]->status);
        self::assertSame(8.0, $entries[0]->oldQuantity);
        self::assertSame(12.0, $entries[0]->newQuantity);
    }

    public function testALineOnlyInThePayloadIsReportedAsNew(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-3');

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
        ]]);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, $payload);

        self::assertSame(OrderConfirmationLineDiffStatus::New, $entries[0]->status);
        self::assertNull($entries[0]->oldName);
        self::assertSame('Development', $entries[0]->newName);
    }

    public function testALineOnlyStoredIsReportedAsRemoved(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-4');
        $orderConfirmation->addLine(new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', null, true, 8.0, 'Stunden', 800.0, true));

        $payload = new LexwarePayload(['lineItems' => []]);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, $payload);

        self::assertSame(OrderConfirmationLineDiffStatus::Removed, $entries[0]->status);
        self::assertNull($entries[0]->newName);
        self::assertSame('Development', $entries[0]->oldName);
    }

    public function testAChangedDescriptionIsReportedAsChanged(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-5');
        $orderConfirmation->addLine(new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', 'Building the thing', true, 8.0, 'Stunden', 800.0, true));

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'description' => 'Building a different thing', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
        ]]);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, $payload);

        self::assertSame(OrderConfirmationLineDiffStatus::Changed, $entries[0]->status);
        self::assertSame('Building the thing', $entries[0]->oldDescription);
        self::assertSame('Building a different thing', $entries[0]->newDescription);
    }

    public function testALineWhoseRemovalWasAlreadyResolvedIsNotReportedAgain(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-6');
        $line = new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', null, true, 8.0, 'Stunden', 800.0, true);
        $line->markRemovedFromSource();
        $orderConfirmation->addLine($line);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, new LexwarePayload(['lineItems' => []]));

        self::assertSame([], $entries);
    }

    public function testALineThatReappearsAfterItsRemovalWasResolvedIsReportedAsChanged(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-7');
        $line = new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', null, true, 8.0, 'Stunden', 800.0, true);
        $line->markRemovedFromSource();
        $orderConfirmation->addLine($line);

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
        ]]);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, $payload);

        self::assertSame(OrderConfirmationLineDiffStatus::Changed, $entries[0]->status);
    }
}
