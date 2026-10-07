<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Support;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\Service\DeliveryLedger;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * A DeliveryLedger on a mocked connection that keeps the
 * emporiqa_webhook_delivery rows in memory, answering the ledger's own
 * three statements the way MySQL would.
 */
trait InMemoryDeliveryLedger
{
    /** @var array<string, array{delivered_at: string, expires_at: int}> */
    private array $ledgerRows = [];

    private function inMemoryLedger(): DeliveryLedger
    {
        /** @var Connection&MockObject $connection */
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            if (str_starts_with($sql, 'INSERT INTO `emporiqa_webhook_delivery`')) {
                foreach ($params as $key => $hash) {
                    if (preg_match('/^h\d+$/', (string) $key)) {
                        $previous = $this->ledgerRows[$hash]['delivered_at'] ?? '0';
                        $this->ledgerRows[$hash] = [
                            'delivered_at' => (float) $previous > (float) $params['at'] ? $previous : $params['at'],
                            'expires_at' => $params['expires'],
                        ];
                    }
                }
            }

            return 1;
        });
        $connection->method('fetchAllKeyValue')->willReturnCallback(function (string $sql, array $params): array {
            $rows = [];
            foreach ($params['hashes'] as $hash) {
                if (isset($this->ledgerRows[$hash]) && $this->ledgerRows[$hash]['expires_at'] > $params['now']) {
                    $rows[$hash] = $this->ledgerRows[$hash]['delivered_at'];
                }
            }

            return $rows;
        });
        $connection->method('fetchOne')->willReturnCallback(
            fn (string $sql, array $params) => isset($this->ledgerRows[$params['hash']]) && $this->ledgerRows[$params['hash']]['expires_at'] > $params['now'] ? '1' : false,
        );

        return new DeliveryLedger($connection, new NullLogger());
    }
}
