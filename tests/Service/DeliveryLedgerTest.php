<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\DeliveryLedger;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Emporiqa\ShopwarePlugin\Tests\Support\InMemoryDeliveryLedger;

class DeliveryLedgerTest extends TestCase
{
    use InMemoryDeliveryLedger;

    public function testOnlyANewerFullStateSupersedesAnEvent(): void
    {
        $ledger = $this->inMemoryLedger();
        $builtAt = 1000.0;
        $ledger->recordDelivered([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
            ['type' => 'page.deleted', 'data' => ['identification_number' => 'page-9']],
        ], 1000.5);

        $kept = $ledger->withoutSuperseded([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
            ['type' => 'product.availability', 'data' => ['identification_number' => 'product-1']],
            ['type' => 'page.updated', 'data' => ['identification_number' => 'page-9']],
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-2']],
            ['type' => 'order.completed', 'data' => ['order_id' => 'o-1']],
        ], $builtAt);

        $this->assertSame([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-2']],
            ['type' => 'order.completed', 'data' => ['order_id' => 'o-1']],
        ], $kept);
    }

    /**
     * Build time, not delivery time: two versions both waiting for a retry
     * may be delivered oldest first, which must not drop the newer one.
     */
    public function testAVersionBuiltEarlierDoesNotSupersedeEvenIfDeliveredLater(): void
    {
        $ledger = $this->inMemoryLedger();
        $ledger->recordDelivered([['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']]], 1000.0);
        $events = [['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']]];

        $this->assertSame($events, $ledger->withoutSuperseded($events, 1010.0));
    }

    /**
     * A stock-only event carries no name, price or text: delivering it must
     * not make a queued full update of the same product look stale.
     */
    public function testAnAvailabilityEventIsNotRecorded(): void
    {
        $ledger = $this->inMemoryLedger();
        $builtAt = 1000.0;
        $ledger->recordDelivered([['type' => 'product.availability', 'data' => ['identification_number' => 'product-1']]], 1001.0);
        $events = [['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']]];

        $this->assertSame($events, $ledger->withoutSuperseded($events, $builtAt));
    }

    public function testBulkSyncsRecordOnlyWhileARetryIsPending(): void
    {
        $ledger = $this->inMemoryLedger();
        $builtAt = 1000.0;
        $events = [['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']]];

        $ledger->recordDelivered($events, 1001.0, true);
        $this->assertSame($events, $ledger->withoutSuperseded($events, $builtAt));

        $ledger->markRetryPending();
        $ledger->recordDelivered($events, 1001.0, true);
        $this->assertSame([], $ledger->withoutSuperseded($events, $builtAt));
    }

    public function testABrokenDatabaseKeepsEveryEvent(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('table missing'));
        $connection->method('fetchAllKeyValue')->willThrowException(new \RuntimeException('table missing'));
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('table missing'));
        $ledger = new DeliveryLedger($connection, new NullLogger());
        $events = [['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']]];

        $ledger->recordDelivered($events, 1.0);
        $ledger->recordDelivered($events, 1.0, true);
        $ledger->markRetryPending();

        $this->assertSame($events, $ledger->withoutSuperseded($events, 0.0));
    }

    /**
     * Two workers can finish out of order: the newest build time is kept.
     */
    public function testAnOlderBuildNeverMovesTheTimeBack(): void
    {
        $ledger = $this->inMemoryLedger();
        $events = [['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']]];
        $ledger->recordDelivered($events, 2000.0);
        $ledger->recordDelivered($events, 1500.0);

        $this->assertSame([], $ledger->withoutSuperseded($events, 1999.0));
        $this->assertSame($events, $ledger->withoutSuperseded($events, 2000.5));
    }
}
