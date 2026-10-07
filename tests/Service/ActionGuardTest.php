<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\Service\ActionGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ActionGuardTest extends TestCase
{
    private Connection&MockObject $connection;

    /** @var array<string, int> bucket hash => hits */
    private array $hits = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []) {
            if (str_starts_with($sql, 'INSERT INTO `emporiqa_action_rate`')) {
                $this->hits[$params['hash']] = ($this->hits[$params['hash']] ?? 0) + 1;
            }

            return 1;
        });
        $this->connection->method('fetchOne')->willReturnCallback(fn (string $sql, array $params) => $this->hits[$params['hash']] ?? false);
    }

    public function testUnderEveryLimitPasses(): void
    {
        $this->assertNull((new ActionGuard($this->connection))->rateLimitHit('st_1', '1042', 'a@b.c', 1_000_000));
    }

    public function testTheEleventhLookupOfOneOrderNumberIsLimitedForTheValue(): void
    {
        $guard = new ActionGuard($this->connection);
        for ($i = 0; $i < ActionGuard::RATE_PER_VALUE; ++$i) {
            $this->assertNull($guard->rateLimitHit('st_1', '1042', 'shopper' . $i . '@b.c', 1_000_000));
        }

        $hit = $guard->rateLimitHit('st_1', '1042', 'other@b.c', 1_000_000);

        $this->assertSame('value', $hit['scope']);
        // Until the 10-minute window ends.
        $this->assertSame(1_000_200 - 1_000_000, $hit['retry_after']);
    }

    public function testEmailIsCountedCaseInsensitively(): void
    {
        $guard = new ActionGuard($this->connection);
        for ($i = 0; $i < ActionGuard::RATE_PER_VALUE; ++$i) {
            $guard->rateLimitHit('st_1', (string) (1000 + $i), 'A@B.C', 1_000_000);
        }

        $this->assertSame('value', $guard->rateLimitHit('st_1', '2000', 'a@b.c', 1_000_000)['scope']);
    }

    public function testTheStoreCeilingHoldsVariedValuesBack(): void
    {
        $guard = new ActionGuard($this->connection);
        for ($i = 0; $i < ActionGuard::RATE_PER_STORE; ++$i) {
            $guard->rateLimitHit('st_1', (string) $i, '', 1_000_000);
        }

        $this->assertSame('store', $guard->rateLimitHit('st_1', 'new', '', 1_000_000)['scope']);
    }

    public function testFailsClosedWhenTheCounterCannotBeWritten(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('table missing'));

        $this->assertSame('store', (new ActionGuard($connection))->rateLimitHit('st_1', '1042', 'a@b.c')['scope']);
    }

    public function testRememberedAnswerIsReadByRequestIdHash(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchAssociative')
            ->with($this->anything(), $this->callback(fn (array $p) => $p['hash'] === hash('sha256', 'st_1|req-1')))
            ->willReturn(['http_code' => '200', 'response' => '{"status":"not_found"}']);

        $this->assertSame([200, '{"status":"not_found"}'], (new ActionGuard($connection))->remembered('st_1', 'req-1'));
    }

    /**
     * One store's traffic never counts against another store of the same shop.
     */
    public function testLimitsArePerStore(): void
    {
        $guard = new ActionGuard($this->connection);
        for ($i = 0; $i < ActionGuard::RATE_PER_VALUE; ++$i) {
            $guard->rateLimitHit('st_1', '1042', '', 1_000_000);
        }

        $this->assertNotNull($guard->rateLimitHit('st_1', '1042', '', 1_000_000));
        $this->assertNull($guard->rateLimitHit('st_2', '1042', '', 1_000_000));
    }

    /**
     * A request_id is remembered per store: another store never gets this
     * store's answer.
     */
    public function testRememberedAnswersArePerStore(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) {
                if (str_starts_with($sql, 'REPLACE')) {
                    $this->assertSame(hash('sha256', 'st_2|req-1'), $params['hash']);
                }

                return 1;
            });

        (new ActionGuard($connection))->remember('st_2', 'req-1', 200, '{}');
    }

    public function testAnEmailOnAStoreDomainIsAValueLimitNotTheStoreCeiling(): void
    {
        $guard = new ActionGuard($this->connection);
        for ($i = 0; $i < ActionGuard::RATE_PER_VALUE; ++$i) {
            $guard->rateLimitHit('st_1', (string) $i, 'shopper@brand.store', 1_000_000);
        }

        $this->assertSame('value', $guard->rateLimitHit('st_1', 'x', 'shopper@brand.store', 1_000_000)['scope']);
    }

    public function testCustomerPricesAreLimitedPerCustomerThenPerStore(): void
    {
        $guard = new ActionGuard($this->connection);
        for ($i = 0; $i < ActionGuard::PRICE_RATE_PER_CUSTOMER; ++$i) {
            $this->assertNull($guard->customerPriceLimitHit('st_1', 'customer-a', 1_000_000));
        }
        $this->assertSame('value', $guard->customerPriceLimitHit('st_1', 'CUSTOMER-A', 1_000_000)['scope']);

        for ($i = 0; $i < ActionGuard::PRICE_RATE_PER_STORE; ++$i) {
            $guard->customerPriceLimitHit('st_1', 'customer-' . $i, 1_000_000);
        }
        $this->assertSame('store', $guard->customerPriceLimitHit('st_1', 'new-customer', 1_000_000)['scope']);
    }

    public function testCustomerPriceLimitsFailClosed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('table missing'));

        $this->assertSame('store', (new ActionGuard($connection))->customerPriceLimitHit('st_1', 'customer-a')['scope']);
    }

    public function testCustomerInfoIsLimitedPerCustomerThenPerStore(): void
    {
        $guard = new ActionGuard($this->connection);
        for ($i = 0; $i < ActionGuard::INFO_RATE_PER_CUSTOMER; ++$i) {
            $this->assertNull($guard->customerInfoLimitHit('st_1', 'customer-a', 1_000_000));
        }
        $this->assertSame('value', $guard->customerInfoLimitHit('st_1', 'CUSTOMER-A', 1_000_000)['scope']);

        for ($i = 0; $i < ActionGuard::INFO_RATE_PER_STORE; ++$i) {
            $guard->customerInfoLimitHit('st_1', 'customer-' . $i, 1_000_000);
        }
        $this->assertSame('store', $guard->customerInfoLimitHit('st_1', 'new-customer', 1_000_000)['scope']);
    }

    /**
     * Its own buckets: a burst of customer_prices calls for one shopper
     * does not use up their customer_info allowance, nor the reverse.
     */
    public function testCustomerInfoAndCustomerPricesCountApart(): void
    {
        $guard = new ActionGuard($this->connection);
        for ($i = 0; $i <= ActionGuard::PRICE_RATE_PER_CUSTOMER; ++$i) {
            $guard->customerPriceLimitHit('st_1', 'customer-a', 1_000_000);
        }

        $this->assertNull($guard->customerInfoLimitHit('st_1', 'customer-a', 1_000_000));
    }

    public function testCustomerInfoLimitsFailClosed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('table missing'));

        $this->assertSame('store', (new ActionGuard($connection))->customerInfoLimitHit('st_1', 'customer-a')['scope']);
    }
}
