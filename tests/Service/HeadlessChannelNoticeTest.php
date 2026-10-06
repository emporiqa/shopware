<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\HeadlessChannelNotice;
use Emporiqa\ShopwarePlugin\Tests\Support\EntityCollectionHelper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * The notice must name the headless channels a merchant actually sells
 * through (they show products), and not Shopware's empty default "Headless"
 * channel, which most shops have and would turn the notice into noise.
 */
class HeadlessChannelNoticeTest extends TestCase
{
    use EntityCollectionHelper;

    private function channel(string $name, string $typeId): SalesChannelEntity
    {
        $channel = new SalesChannelEntity();
        $channel->setId(md5($name));
        $channel->setName($name);
        $channel->setTypeId($typeId);

        return $channel;
    }

    public function testAsksOnlyForActiveHeadlessChannelsWithProductsAndNamesThem(): void
    {
        $captured = null;
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(self::entityCollection(
            $this->channel('Emporiqa test frontend', Defaults::SALES_CHANNEL_TYPE_API),
            $this->channel('Storefront', Defaults::SALES_CHANNEL_TYPE_STOREFRONT),
        ));
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(function (Criteria $criteria) use (&$captured, $result) {
            $captured = $criteria;

            return $result;
        });

        $names = (new HeadlessChannelNotice($repository))->channelsWithProducts(Context::createDefaultContext());

        $this->assertSame(['Emporiqa test frontend'], $names);
        $filters = $captured->getFilters();
        $this->assertContainsEquals(new EqualsFilter('active', true), $filters);
        $this->assertContainsEquals(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_API), $filters);
        $this->assertContainsEquals(
            new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('productVisibilities.id', null)]),
            $filters,
        );
    }

    public function testMessageNamesTheChannelsAndPointsAtTheEmbedCode(): void
    {
        $message = HeadlessChannelNotice::message(['Frontend', 'App']);

        $this->assertStringStartsWith('Not synced (headless sales channel): "Frontend", "App".', $message);
        $this->assertStringContainsString('https://emporiqa.com/docs/widget-embedding/', $message);
    }
}
