<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\ChannelResolver;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Emporiqa\ShopwarePlugin\Tests\Support\EntityCollectionHelper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class ChannelResolverTest extends TestCase
{
    use EntityCollectionHelper;

    private ConfigServiceInterface&MockObject $config;
    private EntityRepository&MockObject $salesChannelRepository;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->salesChannelRepository = $this->createMock(EntityRepository::class);
    }

    private function createResolver(): ChannelResolver
    {
        return new ChannelResolver($this->config, $this->salesChannelRepository);
    }

    private function createSalesChannel(string $id, string $name, string $typeId = Defaults::SALES_CHANNEL_TYPE_STOREFRONT): SalesChannelEntity
    {
        $channel = new SalesChannelEntity();
        $channel->setId($id);
        $channel->setName($name);
        $channel->setTypeId($typeId);
        $channel->setActive(true);

        return $channel;
    }

    public function testExplicitMappingUsedWhenConfigured(): void
    {
        $this->config->method('getChannelMapping')->willReturn([
            'sc-1' => 'main',
            'sc-2' => 'wholesale',
        ]);

        $resolver = $this->createResolver();

        $this->assertSame('main', $resolver->resolveChannelKey('sc-1'));
        $this->assertSame('wholesale', $resolver->resolveChannelKey('sc-2'));
    }

    public function testUnknownChannelFallsBackToSlugifiedId(): void
    {
        $this->config->method('getChannelMapping')->willReturn([
            'sc-1' => 'main',
        ]);

        $resolver = $this->createResolver();

        $this->assertNotEmpty($resolver->resolveChannelKey('unknown-id'));
    }

    public function testSingleChannelGetsSlugifiedName(): void
    {
        $this->config->method('getChannelMapping')->willReturn([]);

        $channels = [$this->createSalesChannel('sc-1', 'Storefront')];

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator($channels));
        $result->method('getEntities')->willReturn(self::entityCollection(...$channels));
        $this->salesChannelRepository->method('search')->willReturn($result);

        $resolver = $this->createResolver();

        $this->assertSame(['sc-1' => 'storefront'], $resolver->getMapping());
        $this->assertSame('storefront', $resolver->resolveChannelKey('sc-1'));
    }

    public function testDuplicateChannelNamesGetDistinctKeys(): void
    {
        $this->config->method('getChannelMapping')->willReturn([]);

        $channels = [
            $this->createSalesChannel('sc-1', 'Storefront'),
            $this->createSalesChannel('sc-2', 'Storefront'),
            $this->createSalesChannel('sc-3', 'Storefront'),
        ];

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator($channels));
        $result->method('getEntities')->willReturn(self::entityCollection(...$channels));
        $this->salesChannelRepository->method('search')->willReturn($result);

        $mapping = $this->createResolver()->getMapping();

        $this->assertSame(['sc-1' => 'storefront', 'sc-2' => 'storefront-2', 'sc-3' => 'storefront-3'], $mapping);
    }

    public function testMultipleChannelsAllGetSlugifiedNames(): void
    {
        $this->config->method('getChannelMapping')->willReturn([]);

        $channels = [
            $this->createSalesChannel('sc-1', 'Main Store'),
            $this->createSalesChannel('sc-2', 'German Store'),
            $this->createSalesChannel('sc-3', 'French Store'),
        ];

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator($channels));
        $result->method('getEntities')->willReturn(self::entityCollection(...$channels));
        $this->salesChannelRepository->method('search')->willReturn($result);

        $resolver = $this->createResolver();
        $mapping = $resolver->getMapping();

        $this->assertSame('main-store', $mapping['sc-1']);
        $this->assertSame('german-store', $mapping['sc-2']);
        $this->assertSame('french-store', $mapping['sc-3']);
    }

    public function testHeadlessChannelsAreSkipped(): void
    {
        $this->config->method('getChannelMapping')->willReturn([]);

        $channels = [
            $this->createSalesChannel('sc-1', 'Storefront'),
            $this->createSalesChannel('sc-api', 'Headless', Defaults::SALES_CHANNEL_TYPE_API),
        ];

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator($channels));
        $result->method('getEntities')->willReturn(self::entityCollection(...$channels));
        $this->salesChannelRepository->method('search')->willReturn($result);

        $resolver = $this->createResolver();

        $this->assertSame(['sc-1' => 'storefront'], $resolver->getMapping());
        $this->assertSame('storefront', $resolver->resolveChannelKey('sc-1'));
    }

    public function testMappingIsCached(): void
    {
        $this->config->method('getChannelMapping')->willReturn([
            'sc-1' => 'retail',
        ]);

        $resolver = $this->createResolver();

        $first = $resolver->getMapping();
        $second = $resolver->getMapping();

        $this->assertSame($first, $second);
    }

    public function testSlugifiesSpecialCharacters(): void
    {
        $this->config->method('getChannelMapping')->willReturn([]);

        $channels = [
            $this->createSalesChannel('sc-1', 'Main Store'),
            $this->createSalesChannel('sc-2', 'Über Store (DE)'),
            $this->createSalesChannel('sc-3', 'Café Shop'),
        ];

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator($channels));
        $result->method('getEntities')->willReturn(self::entityCollection(...$channels));
        $this->salesChannelRepository->method('search')->willReturn($result);

        $resolver = $this->createResolver();
        $mapping = $resolver->getMapping();

        $this->assertSame('main-store', $mapping['sc-1']);
        $this->assertSame('uber-store-de', $mapping['sc-2']);
        $this->assertSame('cafe-shop', $mapping['sc-3']);
    }

    public function testAutoDetectSortsByCreatedAtAscending(): void
    {
        $this->config->method('getChannelMapping')->willReturn([]);

        $channels = [
            $this->createSalesChannel('sc-1', 'Store A'),
            $this->createSalesChannel('sc-2', 'Store B'),
        ];

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator($channels));
        $result->method('getEntities')->willReturn(self::entityCollection(...$channels));

        $capturedCriteria = null;
        $this->salesChannelRepository->method('search')
            ->willReturnCallback(function (Criteria $criteria) use ($result, &$capturedCriteria) {
                $capturedCriteria = $criteria;

                return $result;
            });

        $resolver = $this->createResolver();
        $resolver->getMapping();

        $this->assertNotNull($capturedCriteria);
        $sortings = $capturedCriteria->getSorting();
        $this->assertCount(1, $sortings);
        $this->assertSame('createdAt', $sortings[0]->getField());
        $this->assertSame(FieldSorting::ASCENDING, $sortings[0]->getDirection());
    }

    public function testNoChannelsReturnsEmptyMapping(): void
    {
        $this->config->method('getChannelMapping')->willReturn([]);

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator([]));
        $result->method('getEntities')->willReturn(self::entityCollection(...[]));
        $this->salesChannelRepository->method('search')->willReturn($result);

        $resolver = $this->createResolver();

        $this->assertSame([], $resolver->getMapping());
    }
}
