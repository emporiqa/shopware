<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\ScheduledTask;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\ScheduledTask\PriceRuleBoundaryTask;
use Emporiqa\ShopwarePlugin\ScheduledTask\PriceRuleBoundaryTaskHandler;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class PriceRuleBoundaryTaskHandlerTest extends TestCase
{
    private const NOW = 2_000_000_000;
    private const RULE_ENDED = '0190aaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const RULE_LATER = '0190bbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Connection&MockObject $connection;
    private SystemConfigService&MockObject $systemConfig;
    private ConfigServiceInterface&MockObject $config;
    private SyncServiceInterface&MockObject $sync;

    /** @var array<string, mixed> */
    private array $stored = [];

    /** @var list<array<string, string>> */
    private array $conditions = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(fn () => $this->conditions);
        $this->connection->method('fetchFirstColumn')->willReturn(['0190cccccccccccccccccccccccccccc']);

        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->systemConfig->method('getInt')->willReturnCallback(fn (string $key) => (int) ($this->stored[$key] ?? 0));
        $this->systemConfig->method('set')->willReturnCallback(function (string $key, $value): void {
            $this->stored[$key] = $value;
        });

        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncProductsEnabled')->willReturn(true);
        $this->sync = $this->createMock(SyncServiceInterface::class);
    }

    /**
     * A sale that started or ended since the last run re-syncs the products
     * priced by its rule, so the chat never quotes an ended sale; a range
     * whose boundaries are still ahead re-syncs nothing.
     */
    public function testProductsOfARuleWhoseRangeEndedAreResynced(): void
    {
        $this->stored[PriceRuleBoundaryTaskHandler::CHECKED_AT_KEY] = self::NOW - 900;
        $this->conditions = [
            ['rule_id' => self::RULE_ENDED, 'value' => json_encode(['fromDate' => date(DATE_ATOM, self::NOW - 86400), 'toDate' => date(DATE_ATOM, self::NOW - 60), 'useTime' => true])],
            ['rule_id' => self::RULE_LATER, 'value' => json_encode(['fromDate' => date(DATE_ATOM, self::NOW - 86400), 'toDate' => date(DATE_ATOM, self::NOW + 3600), 'useTime' => true])],
        ];
        $this->connection->expects($this->once())->method('fetchFirstColumn')
            ->with($this->anything(), $this->callback(fn (array $p) => \count($p['ruleIds']) === 1 && bin2hex($p['ruleIds'][0]) === self::RULE_ENDED));
        $this->sync->expects($this->once())->method('resyncProducts')->with(['0190cccccccccccccccccccccccccccc']);

        $this->assertSame(['0190cccccccccccccccccccccccccccc'], $this->handler()->runAt(self::NOW));
        $this->assertSame(self::NOW, $this->stored[PriceRuleBoundaryTaskHandler::CHECKED_AT_KEY]);
    }

    public function testNoBoundaryPassedMeansNoResync(): void
    {
        $this->stored[PriceRuleBoundaryTaskHandler::CHECKED_AT_KEY] = self::NOW - 900;
        $this->conditions = [
            ['rule_id' => self::RULE_LATER, 'value' => json_encode(['fromDate' => date(DATE_ATOM, self::NOW - 86400), 'toDate' => date(DATE_ATOM, self::NOW + 3600), 'useTime' => true])],
            ['rule_id' => self::RULE_ENDED, 'value' => 'not json'],
        ];
        $this->sync->expects($this->never())->method('resyncProducts');

        $this->assertSame([], $this->handler()->runAt(self::NOW));
    }

    public function testTheFirstRunLooksBackOneInterval(): void
    {
        $this->conditions = [
            ['rule_id' => self::RULE_ENDED, 'value' => json_encode(['fromDate' => null, 'toDate' => date(DATE_ATOM, self::NOW - PriceRuleBoundaryTask::getDefaultInterval() + 10), 'useTime' => true])],
        ];
        $this->sync->expects($this->once())->method('resyncProducts');

        $this->handler()->runAt(self::NOW);
    }

    public function testNothingRunsWhenProductSyncIsOff(): void
    {
        $config = $this->createMock(ConfigServiceInterface::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isSyncProductsEnabled')->willReturn(false);
        $this->connection->expects($this->never())->method('fetchAllAssociative');

        (new PriceRuleBoundaryTaskHandler($this->createMock(EntityRepository::class), new NullLogger(), $this->connection, $this->systemConfig, $config, $this->sync))->runAt(self::NOW);
    }

    private function handler(): PriceRuleBoundaryTaskHandler
    {
        return new PriceRuleBoundaryTaskHandler($this->createMock(EntityRepository::class), new NullLogger(), $this->connection, $this->systemConfig, $this->config, $this->sync);
    }
}
