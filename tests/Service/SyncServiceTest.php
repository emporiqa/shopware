<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Exception\RateLimitException;
use Emporiqa\ShopwarePlugin\Service\ChannelResolverInterface;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\ProductFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\SyncService;
use Emporiqa\ShopwarePlugin\Service\WebhookClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Emporiqa\ShopwarePlugin\Tests\Support\EntityCollectionHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SyncServiceTest extends TestCase
{
    use EntityCollectionHelper;

    private ConfigServiceInterface&MockObject $config;
    private WebhookClientInterface&MockObject $webhookClient;
    private ProductFormatterInterface&MockObject $productFormatter;
    private CmsPageFormatterInterface&MockObject $cmsPageFormatter;
    private EntityRepository&MockObject $productRepository;
    private EntityRepository&MockObject $landingPageRepository;
    private EntityRepository&MockObject $categoryRepository;
    private EntityRepository&MockObject $salesChannelRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private ChannelResolverInterface&MockObject $channelResolver;
    private LoggerInterface&MockObject $logger;
    private SyncService $service;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->webhookClient = $this->createMock(WebhookClientInterface::class);
        $this->productFormatter = $this->createMock(ProductFormatterInterface::class);
        $this->cmsPageFormatter = $this->createMock(CmsPageFormatterInterface::class);
        $this->productRepository = $this->createMock(EntityRepository::class);
        $this->landingPageRepository = $this->createMock(EntityRepository::class);
        $this->categoryRepository = $this->createMock(EntityRepository::class);
        $this->salesChannelRepository = $this->createMock(EntityRepository::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->channelResolver = $this->createMock(ChannelResolverInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getBatchSize')->willReturn(200);
        $this->channelResolver->method('getMapping')->willReturn(['sc-1' => '']);
        $this->mockSalesChannelSearch();

        $this->service = new SyncService(
            $this->config,
            $this->webhookClient,
            $this->productFormatter,
            $this->cmsPageFormatter,
            $this->productRepository,
            $this->landingPageRepository,
            $this->categoryRepository,
            $this->salesChannelRepository,
            $this->eventDispatcher,
            $this->channelResolver,
            $this->logger,
        );
    }

    // --- resyncProducts ---

    /**
     * A targeted re-sync (sale start/end) sends product.updated for exactly
     * the given products, outside any sync session, with the same criteria
     * as a full sync (active parents with their advanced prices).
     */
    public function testResyncProductsSendsUpdatesForTheGivenIdsOnly(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $searched = [];
        $this->productRepository->method('search')
            ->willReturnCallback(function (Criteria $c) use (&$searched, $product) {
                $searched[] = $c;

                return $this->wrapSearchResult(\count($searched) === 1 ? [$product] : [], ProductCollection::class);
            });
        $this->productFormatter->method('formatProduct')->with($product, $this->anything(), null)
            ->willReturn([['identification_number' => 'product-p-1']]);
        $this->webhookClient->expects($this->never())->method('startSyncSession');
        $this->webhookClient->expects($this->once())->method('sendBatchEvents')
            ->with([['type' => 'product.updated', 'data' => ['identification_number' => 'product-p-1']]])
            ->willReturn(true);

        $this->assertSame(1, $this->service->resyncProducts(['p-1', 'p-2']));
        $this->assertSame(['p-1', 'p-2'], $searched[0]->getIds());
        $this->assertTrue($searched[0]->hasAssociation('prices'));
    }

    // --- F1: zero-item guard ---

    public function testSyncProductsDoesNotCompleteSessionWhenZeroItemsSynced(): void
    {
        $this->webhookClient->method('startSyncSession')->willReturn(true);
        $this->mockProductSearchSequence([]);

        $this->webhookClient->expects($this->never())->method('completeSyncSession');

        $result = $this->service->syncProducts();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['products']);
        $this->assertStringContainsString('no items were synced', implode(' ', $result['errors']));
    }

    public function testSyncPagesDoesNotCompleteSessionWhenZeroItemsSynced(): void
    {
        $this->webhookClient->method('startSyncSession')->willReturn(true);
        $this->mockLandingPageSearchSequence([]);
        $this->mockShopPageSearchSequence([]);

        $this->webhookClient->expects($this->never())->method('completeSyncSession');

        $result = $this->service->syncPages();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['pages']);
        $this->assertStringContainsString('no items were synced', implode(' ', $result['errors']));
    }

    public function testSyncProductsCompletesSessionWhenItemsWereSynced(): void
    {
        $this->webhookClient->method('startSyncSession')->willReturn(true);
        $this->webhookClient->method('sendBatchEvents')->willReturn(true);
        $this->webhookClient->expects($this->once())->method('completeSyncSession')->willReturn(true);

        $product = $this->createMock(ProductEntity::class);
        $this->mockProductSearchSequence([$product]);

        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-1'],
        ]);

        $result = $this->service->syncProducts();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['products']);
    }

    // --- F2: chunking at 50 events per request ---

    public function testSyncProductsChunksEventsAt50PerRequest(): void
    {
        $this->webhookClient->method('startSyncSession')->willReturn(true);
        $this->webhookClient->method('completeSyncSession')->willReturn(true);

        // 60 products, each formatter call returns 1 event => 60 events total,
        // which must be split into a 50-event chunk and a 10-event chunk.
        $products = [];
        for ($i = 0; $i < 60; $i++) {
            $products[] = $this->createMock(ProductEntity::class);
        }
        $this->mockProductSearchSequence($products);

        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-x'],
        ]);

        $chunkSizes = [];
        $this->webhookClient
            ->expects($this->exactly(2))
            ->method('sendBatchEvents')
            ->willReturnCallback(function (array $events) use (&$chunkSizes) {
                $chunkSizes[] = \count($events);

                return true;
            });

        $result = $this->service->syncProducts();

        $this->assertSame([50, 10], $chunkSizes);
        $this->assertTrue($result['success']);
    }

    // --- F3: failed-chunk error includes webhook client's last error detail ---

    public function testFailedChunkErrorIncludesLastErrorDetail(): void
    {
        $this->webhookClient->method('startSyncSession')->willReturn(true);
        $this->webhookClient->method('sendBatchEvents')->willReturn(false);
        $this->webhookClient->method('getLastError')->willReturn('Store not found');

        $product = $this->createMock(ProductEntity::class);
        $this->mockProductSearchSequence([$product]);

        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-1'],
        ]);

        $result = $this->service->syncProducts();

        $this->assertFalse($result['success']);
        $joined = implode(' ', $result['errors']);
        $this->assertStringContainsString('Failed to send product batch', $joined);
        $this->assertStringContainsString('Store not found', $joined);
    }

    public function testRateLimitStopsSyncAndRecordsError(): void
    {
        $this->webhookClient->method('startSyncSession')->willReturn(true);
        $this->webhookClient->method('sendBatchEvents')->willThrowException(new RateLimitException('Rate limit exceeded (HTTP 429).'));

        $product = $this->createMock(ProductEntity::class);
        $this->mockProductSearchSequence([$product]);

        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-1'],
        ]);

        $this->webhookClient->expects($this->never())->method('completeSyncSession');

        $result = $this->service->syncProducts();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Rate limited', implode(' ', $result['errors']));
    }

    // --- countItems ---

    public function testCountItemsReturnsProductTotal(): void
    {
        $this->productRepository->method('search')->willReturn($this->wrapTotalResult(42));

        $this->assertSame(42, $this->service->countItems('products'));
    }

    public function testCountItemsSumsLandingPagesAndShopPages(): void
    {
        $this->landingPageRepository->method('search')->willReturn($this->wrapTotalResult(5));
        $this->categoryRepository->method('search')->willReturn($this->wrapTotalResult(3));

        $this->assertSame(8, $this->service->countItems('pages'));
    }

    public function testCountItemsReturnsZeroForUnknownEntity(): void
    {
        $this->assertSame(0, $this->service->countItems('unknown'));
    }

    // --- syncBatch: products (keyset paging by id) ---

    public function testSyncBatchProductsFormatsAndSendsEventsForRequestedBatch(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $this->productRepository->method('search')->willReturn($this->wrapSearchResult([$product], ProductCollection::class));
        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-1'],
        ]);

        $sentEvents = [];
        $this->webhookClient->method('sendBatchEvents')->willReturnCallback(function (array $events) use (&$sentEvents) {
            $sentEvents = $events;

            return true;
        });

        $result = $this->service->syncBatch('products', '', 'sess-1');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['events']);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('product.updated', $sentEvents[0]['type']);
        // Fewer than a full batch: that was the last one.
        $this->assertNull($result['nextCursor']);
    }

    public function testSyncBatchProductsPagesByIdNotOffset(): void
    {
        $afterId = Uuid::randomHex();
        $capturedCriteria = null;
        $this->productRepository
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria) use (&$capturedCriteria) {
                $capturedCriteria = $criteria;

                return $this->wrapSearchResult([], ProductCollection::class);
            });

        $this->service->syncBatch('products', $afterId, 'sess-1');

        $this->assertNull($capturedCriteria->getOffset());
        $this->assertSame(200, $capturedCriteria->getLimit());
        $range = array_values(array_filter($capturedCriteria->getFilters(), fn ($f) => $f instanceof RangeFilter));
        $this->assertCount(1, $range);
        $this->assertSame('id', $range[0]->getField());
        $this->assertSame(Uuid::fromHexToBytes($afterId), $range[0]->getParameter(RangeFilter::GT));
    }

    public function testSyncBatchProductsReturnsLastIdAsNextCursorForAFullBatch(): void
    {
        $service = $this->createServiceWithBatchSize(2);
        $first = new ProductEntity();
        $first->setId(Uuid::randomHex());
        $last = new ProductEntity();
        $last->setId(Uuid::randomHex());
        $this->productRepository->method('search')->willReturn($this->wrapSearchResult([$first, $last], ProductCollection::class));
        $this->productFormatter->method('formatProduct')->willReturn([]);

        $result = $service->syncBatch('products', '', 'sess-1');

        $this->assertSame($last->getId(), $result['nextCursor']);
    }

    public function testSyncBatchUsesPinnedBatchSizeOverConfig(): void
    {
        $capturedCriteria = null;
        $this->productRepository
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria) use (&$capturedCriteria) {
                $capturedCriteria = $criteria;

                return $this->wrapSearchResult([], ProductCollection::class);
            });

        // Config mock returns 200, but the session-pinned size of 50 wins.
        $this->service->syncBatch('products', '', 'sess-1', 50);

        $this->assertSame(50, $capturedCriteria->getLimit());
    }

    public function testSyncBatchProductsIncludesLastErrorDetailOnFailure(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $this->productRepository->method('search')->willReturn($this->wrapSearchResult([$product], ProductCollection::class));
        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-1'],
        ]);
        $this->webhookClient->method('sendBatchEvents')->willReturn(false);
        $this->webhookClient->method('getLastError')->willReturn('Store not found');

        $result = $this->service->syncBatch('products', '', 'sess-1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Store not found', $result['error']);
    }

    /**
     * When Emporiqa cannot be reached every chunk of a batch fails with the
     * same error: the admin sync log shows it once, and never the keyset
     * cursor as an "offset" (an id, or empty for the first page).
     */
    public function testSyncBatchReportsARepeatedChunkErrorOnceWithoutTheCursor(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $this->productRepository->method('search')->willReturn($this->wrapSearchResult([$product], ProductCollection::class));
        $this->productFormatter->method('formatProduct')->willReturn(array_map(
            static fn (int $i): array => ['identification_number' => 'product-1-' . $i],
            range(1, 120),
        ));
        $this->webhookClient->expects($this->exactly(3))->method('sendBatchEvents')->willReturn(false);
        $this->webhookClient->method('getLastError')->willReturn('cURL error 28: Connection timeout');

        $cursor = Uuid::randomHex();
        $result = $this->service->syncBatch('products', $cursor, 'sess-1');

        $this->assertFalse($result['success']);
        $this->assertSame('Failed to send product batch. cURL error 28: Connection timeout', $result['error']);
        $this->assertStringNotContainsString($cursor, $result['error']);
    }

    public function testSyncBatchReturnsEarlyWhenNoChannelContextsResolved(): void
    {
        $emptyResult = $this->createMock(EntitySearchResult::class);
        $emptyResult->method('getIterator')->willReturn(new \ArrayIterator([]));
        $emptyResult->method('getEntities')->willReturn(self::entityCollection(...[]));

        $salesChannelRepository = $this->createMock(EntityRepository::class);
        $salesChannelRepository->method('search')->willReturn($emptyResult);

        $service = new SyncService(
            $this->config,
            $this->webhookClient,
            $this->productFormatter,
            $this->cmsPageFormatter,
            $this->productRepository,
            $this->landingPageRepository,
            $this->categoryRepository,
            $salesChannelRepository,
            $this->eventDispatcher,
            $this->channelResolver,
            $this->logger,
        );

        $this->productRepository->expects($this->never())->method('search');

        $result = $service->syncBatch('products', '', 'sess-1');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['events']);
        $this->assertNull($result['nextCursor']);
    }

    public function testSyncBatchUnknownEntityReturnsError(): void
    {
        $result = $this->service->syncBatch('unknown', '', 'sess-1');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['processed']);
        $this->assertArrayHasKey('error', $result);
    }

    // --- syncBatch: pages (landing pages, then shop pages, each by id) ---

    public function testSyncBatchPagesMovesToShopPagesAfterTheLastLandingPage(): void
    {
        $service = $this->createServiceWithBatchSize(3);
        $landingPage = new LandingPageEntity();
        $landingPage->setId(Uuid::randomHex());
        $this->landingPageRepository->method('search')
            ->willReturn($this->wrapSearchResult([$landingPage], EntityCollection::class));
        $this->categoryRepository->expects($this->never())->method('search');
        $this->cmsPageFormatter->method('formatLandingPage')->willReturn(['id' => 'lp']);
        $this->webhookClient->method('sendBatchEvents')->willReturn(true);

        $result = $service->syncBatch('pages', '', 'sess-1');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame('shop:', $result['nextCursor']);
    }

    public function testSyncBatchPagesContinuesLandingPagesAfterAFullBatch(): void
    {
        $service = $this->createServiceWithBatchSize(2);
        $first = new LandingPageEntity();
        $first->setId(Uuid::randomHex());
        $last = new LandingPageEntity();
        $last->setId(Uuid::randomHex());
        $this->landingPageRepository->method('search')
            ->willReturn($this->wrapSearchResult([$first, $last], EntityCollection::class));
        $this->cmsPageFormatter->method('formatLandingPage')->willReturn(['id' => 'lp']);
        $this->webhookClient->method('sendBatchEvents')->willReturn(true);

        $result = $service->syncBatch('pages', '', 'sess-1');

        $this->assertSame('landing:' . $last->getId(), $result['nextCursor']);
    }

    public function testSyncBatchShopPagesPageByIdAndEnd(): void
    {
        $service = $this->createServiceWithBatchSize(3);
        $afterId = Uuid::randomHex();
        $category = $this->createMock(CategoryEntity::class);

        $capturedShopCriteria = null;
        $this->categoryRepository
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria) use (&$capturedShopCriteria, $category) {
                $capturedShopCriteria = $criteria;

                return $this->wrapSearchResult([$category], EntityCollection::class);
            });
        $this->landingPageRepository->expects($this->never())->method('search');
        $this->cmsPageFormatter->method('formatShopPage')->willReturn(['id' => 'sp']);
        $this->webhookClient->method('sendBatchEvents')->willReturn(true);

        $result = $service->syncBatch('pages', 'shop:' . $afterId, 'sess-1');

        $this->assertNull($capturedShopCriteria->getOffset());
        $this->assertSame(3, $capturedShopCriteria->getLimit());
        $this->assertSame(1, $result['processed']);
        $this->assertNull($result['nextCursor']);
    }

    public function testBuildChannelContextsIncludesAllLanguagesWhenNoneConfigured(): void
    {
        $this->config->method('getEnabledLanguages')->willReturn([]);

        $contexts = $this->service->buildChannelContexts();

        $this->assertSame(['en-GB', 'de-DE'], array_column($contexts[''], 'languageCode'));
    }

    public function testBuildChannelContextsSkipsSalesChannelsNotEnabled(): void
    {
        $this->config->method('getEnabledSalesChannels')->willReturn(['sc-other']);

        $this->assertSame([], $this->service->buildChannelContexts());
    }

    public function testBuildChannelContextsIncludesEnabledSalesChannel(): void
    {
        $this->config->method('getEnabledSalesChannels')->willReturn(['sc-1']);

        $this->assertSame(['en-GB', 'de-DE'], array_column($this->service->buildChannelContexts()[''], 'languageCode'));
    }

    public function testBuildChannelContextsSkipsLanguagesNotEnabled(): void
    {
        $this->config->method('getEnabledLanguages')->willReturn(['de-DE']);

        $contexts = $this->service->buildChannelContexts();

        $this->assertSame(['de-DE'], array_column($contexts[''], 'languageCode'));
    }

    public function testBuildChannelContextsReturnsEmptyWhenNoDomainLanguageIsEnabled(): void
    {
        $this->config->method('getEnabledLanguages')->willReturn(['fr-FR']);

        $this->assertSame([], $this->service->buildChannelContexts());
    }

    public function testBuildChannelContextsCarriesCurrencyFactorAndRoundingAsStrings(): void
    {
        $context = $this->service->buildChannelContexts()[''][0];

        $this->assertSame('1.17085', $context['currencyFactor']);
        $this->assertSame('2', $context['currencyDecimals']);
        $this->assertSame('0.05', $context['currencyInterval']);
    }

    private function createServiceWithBatchSize(int $batchSize): SyncService
    {
        $config = $this->createMock(ConfigServiceInterface::class);
        $config->method('getBatchSize')->willReturn($batchSize);

        return new SyncService(
            $config,
            $this->webhookClient,
            $this->productFormatter,
            $this->cmsPageFormatter,
            $this->productRepository,
            $this->landingPageRepository,
            $this->categoryRepository,
            $this->salesChannelRepository,
            $this->eventDispatcher,
            $this->channelResolver,
            $this->logger,
        );
    }

    private function wrapTotalResult(int $total): EntitySearchResult&MockObject
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getTotal')->willReturn($total);

        return $result;
    }

    /**
     * @param list<ProductEntity&MockObject> $products
     */
    private function mockProductSearchSequence(array $products): void
    {
        $withResults = $this->wrapSearchResult($products, ProductCollection::class);
        $empty = $this->wrapSearchResult([], ProductCollection::class);

        if (empty($products)) {
            $this->productRepository->method('search')->willReturn($empty);

            return;
        }

        $this->productRepository->method('search')->willReturnOnConsecutiveCalls($withResults, $empty);
    }

    /**
     * @param list<object> $pages
     */
    private function mockLandingPageSearchSequence(array $pages): void
    {
        $empty = $this->wrapSearchResult([], EntityCollection::class);

        if (empty($pages)) {
            $this->landingPageRepository->method('search')->willReturn($empty);

            return;
        }

        $withResults = $this->wrapSearchResult($pages, EntityCollection::class);
        $this->landingPageRepository->method('search')->willReturnOnConsecutiveCalls($withResults, $empty);
    }

    /**
     * @param list<object> $pages
     */
    private function mockShopPageSearchSequence(array $pages): void
    {
        $empty = $this->wrapSearchResult([], EntityCollection::class);

        if (empty($pages)) {
            $this->categoryRepository->method('search')->willReturn($empty);

            return;
        }

        $withResults = $this->wrapSearchResult($pages, EntityCollection::class);
        $this->categoryRepository->method('search')->willReturnOnConsecutiveCalls($withResults, $empty);
    }

    /**
     * @param list<object> $elements
     */
    private function wrapSearchResult(array $elements, string $collectionClass): EntitySearchResult&MockObject
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator($elements));
        $result->method('getEntities')->willReturn(self::entityCollection(...$elements));
        $result->method('count')->willReturn(\count($elements));

        return $result;
    }

    private function mockSalesChannelSearch(): void
    {
        $currency = new CurrencyEntity();
        $currency->setId(Uuid::randomHex());
        $currency->setIsoCode('EUR');
        $currency->setFactor(1.17085);
        $currency->setItemRounding(new CashRoundingConfig(2, 0.05, true));

        $domains = [];
        foreach (['en-GB' => 'https://shop.example.com', 'de-DE' => 'https://shop.example.com/de'] as $code => $url) {
            $locale = new LocaleEntity();
            $locale->setId(Uuid::randomHex());
            $locale->setCode($code);

            $language = new LanguageEntity();
            $language->setId(Uuid::randomHex());
            $language->setLocale($locale);

            $domain = new SalesChannelDomainEntity();
            $domain->setId(Uuid::randomHex());
            $domain->setUrl($url);
            $domain->setLanguageId($language->getId());
            $domain->setLanguage($language);
            $domain->setCurrencyId($currency->getId());
            $domain->setCurrency($currency);
            $domains[] = $domain;
        }

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-1');
        $salesChannel->setActive(true);
        $salesChannel->setTypeId(Defaults::SALES_CHANNEL_TYPE_STOREFRONT);
        $salesChannel->setNavigationCategoryId('root-nav');
        $salesChannel->setDomains(new SalesChannelDomainCollection($domains));

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator([$salesChannel]));
        $result->method('getEntities')->willReturn(self::entityCollection(...[$salesChannel]));
        $this->salesChannelRepository->method('search')->willReturn($result);
    }
}
