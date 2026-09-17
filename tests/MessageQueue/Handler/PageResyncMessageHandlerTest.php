<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\MessageQueue\Handler;

use Emporiqa\ShopwarePlugin\Event\PostPageFormatEvent;
use Emporiqa\ShopwarePlugin\MessageQueue\Handler\PageResyncMessageHandler;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\LandingPage\LandingPageCollection;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class PageResyncMessageHandlerTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private SyncServiceInterface&MockObject $syncService;
    private CmsPageFormatterInterface&MockObject $cmsPageFormatter;
    private EntityRepository&MockObject $landingPageRepository;
    private EntityRepository&MockObject $categoryRepository;
    private MessageBusInterface&MockObject $messageBus;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private PageResyncMessageHandler $handler;

    /** @var list<array{type: string, data: array<string, mixed>}> */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);
        $this->syncService = $this->createMock(SyncServiceInterface::class);
        $this->syncService->method('buildChannelContexts')->willReturn([
            '' => [['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en']],
        ]);
        $this->cmsPageFormatter = $this->createMock(CmsPageFormatterInterface::class);
        $this->cmsPageFormatter->method('formatPageDelete')->willReturnCallback(fn (string $id) => [['identification_number' => 'page-' . $id]]);
        $this->landingPageRepository = $this->createMock(EntityRepository::class);
        $this->categoryRepository = $this->createMock(EntityRepository::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->method('dispatch')->willReturnCallback(function ($message) {
            if ($message instanceof WebhookMessage) {
                $this->dispatchedEvents = array_merge($this->dispatchedEvents, $message->getEvents());
            }

            return new Envelope($message);
        });
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $this->handler = new PageResyncMessageHandler(
            $this->config,
            $this->syncService,
            $this->cmsPageFormatter,
            $this->landingPageRepository,
            $this->categoryRepository,
            $this->messageBus,
            $this->eventDispatcher,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testLandingPagesAreFormattedAndQueuedAsCreatedOrUpdated(): void
    {
        $this->mockLandingPages([$this->landingPage('lp-1'), $this->landingPage('lp-2')]);
        $this->cmsPageFormatter->method('formatLandingPage')->willReturnCallback(
            fn (LandingPageEntity $page) => ['identification_number' => 'page-' . $page->getId()],
        );
        $this->eventDispatcher->expects($this->exactly(2))->method('dispatch')->with($this->isInstanceOf(PostPageFormatEvent::class));

        ($this->handler)(new PageResyncMessage(landingPageIds: ['lp-1', 'lp-2'], createdIds: ['lp-2']));

        $this->assertSame([
            ['type' => 'page.updated', 'data' => ['identification_number' => 'page-lp-1']],
            ['type' => 'page.created', 'data' => ['identification_number' => 'page-lp-2']],
        ], $this->dispatchedEvents);
    }

    public function testInactiveLandingPageIsDeleted(): void
    {
        $this->mockLandingPages([$this->landingPage('lp-1', active: false)]);
        $this->cmsPageFormatter->expects($this->never())->method('formatLandingPage');

        ($this->handler)(new PageResyncMessage(landingPageIds: ['lp-1']));

        $this->assertSame([['type' => 'page.deleted', 'data' => ['identification_number' => 'page-lp-1']]], $this->dispatchedEvents);
    }

    public function testUnreachableLandingPageIsDeletedUnlessJustCreated(): void
    {
        $this->mockLandingPages([$this->landingPage('lp-old'), $this->landingPage('lp-new')]);
        $this->cmsPageFormatter->method('formatLandingPage')->willReturn(null);

        ($this->handler)(new PageResyncMessage(landingPageIds: ['lp-old', 'lp-new'], createdIds: ['lp-new']));

        // Nothing exists remotely for a page that was just created, so no delete for it.
        $this->assertSame([['type' => 'page.deleted', 'data' => ['identification_number' => 'page-lp-old']]], $this->dispatchedEvents);
    }

    public function testShopPageCategoryIsUpdated(): void
    {
        $this->mockCategories([$this->category('cat-1', layoutType: 'page')]);
        $this->cmsPageFormatter->method('formatShopPage')->willReturn(['identification_number' => 'page-cat-1']);

        ($this->handler)(new PageResyncMessage(categoryIds: ['cat-1']));

        $this->assertSame([['type' => 'page.updated', 'data' => ['identification_number' => 'page-cat-1']]], $this->dispatchedEvents);
    }

    public function testCategoryThatIsNoLongerAShopPageIsDeletedOnlyOnStructuralChange(): void
    {
        $this->mockCategories([$this->category('cat-listing', layoutType: 'product_list'), $this->category('cat-touched', layoutType: 'product_list')]);
        $this->cmsPageFormatter->expects($this->never())->method('formatShopPage');

        ($this->handler)(new PageResyncMessage(categoryIds: ['cat-listing', 'cat-touched'], structuralCategoryIds: ['cat-touched']));

        $this->assertSame([['type' => 'page.deleted', 'data' => ['identification_number' => 'page-cat-touched']]], $this->dispatchedEvents);
    }

    public function testListingCategoriesAreNotLoadedWithTheirLayout(): void
    {
        $listing = $this->category('cat-listing', layoutType: 'product_list');
        $shopPage = $this->category('cat-page', layoutType: 'page');

        $calls = [];
        $this->categoryRepository->method('search')->willReturnCallback(function (Criteria $criteria) use (&$calls, $listing, $shopPage) {
            $calls[] = $criteria;

            return $this->searchResult('category', new CategoryCollection(
                \in_array('cat-listing', $criteria->getIds(), true) ? [$listing, $shopPage] : [$shopPage],
            ));
        });
        $this->cmsPageFormatter->method('formatShopPage')->willReturn(['identification_number' => 'page-cat-page']);

        ($this->handler)(new PageResyncMessage(categoryIds: ['cat-listing', 'cat-page']));

        $this->assertCount(2, $calls);
        // First read: both ids, layout association only; second read: shop pages with the full layout tree
        $this->assertSame(['cat-listing', 'cat-page'], $calls[0]->getIds());
        $this->assertArrayNotHasKey('cmsPage.sections', $calls[0]->getAssociations());
        $this->assertSame(['cat-page'], $calls[1]->getIds());
        $this->assertArrayHasKey('cmsPage', $calls[1]->getAssociations());
        $this->assertSame([['type' => 'page.updated', 'data' => ['identification_number' => 'page-cat-page']]], $this->dispatchedEvents);
    }

    public function testAFailingPageDoesNotAbortTheMessage(): void
    {
        $this->mockLandingPages([$this->landingPage('lp-bad'), $this->landingPage('lp-good')]);
        $this->cmsPageFormatter->method('formatLandingPage')->willReturnCallback(function (LandingPageEntity $page) {
            if ($page->getId() === 'lp-bad') {
                throw new \RuntimeException('broken element');
            }

            return ['identification_number' => 'page-' . $page->getId()];
        });

        ($this->handler)(new PageResyncMessage(landingPageIds: ['lp-bad', 'lp-good']));

        $this->assertSame([['type' => 'page.updated', 'data' => ['identification_number' => 'page-lp-good']]], $this->dispatchedEvents);
    }

    public function testTreeRootCategoriesAreIgnored(): void
    {
        $root = $this->category('root', layoutType: 'landingpage');
        $root->setParentId(null);
        $this->mockCategories([$root]);

        ($this->handler)(new PageResyncMessage(categoryIds: ['root'], structuralCategoryIds: ['root']));

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testLayoutResyncUpdatesEveryPageUsingTheLayouts(): void
    {
        $capturedCriteria = [];
        $this->landingPageRepository->method('search')->willReturnCallback(
            function (Criteria $criteria) use (&$capturedCriteria) {
                $capturedCriteria[] = $criteria;

                return $criteria->getOffset() === 0
                    ? $this->searchResult('landing_page', new LandingPageCollection([$this->landingPage('lp-1'), $this->landingPage('lp-2')]))
                    : $this->searchResult('landing_page', new LandingPageCollection());
            },
        );
        $this->categoryRepository->method('search')->willReturnCallback(
            fn (Criteria $criteria) => $criteria->getOffset() === 0
                ? $this->searchResult('category', new CategoryCollection([$this->category('cat-1', layoutType: 'page')]))
                : $this->searchResult('category', new CategoryCollection()),
        );

        // lp-2 is not reachable anymore and is removed by the layout re-sync.
        $this->cmsPageFormatter->method('formatLandingPage')->willReturnCallback(
            fn (LandingPageEntity $page) => $page->getId() === 'lp-1' ? ['identification_number' => 'page-lp-1'] : null,
        );
        $this->cmsPageFormatter->method('formatShopPage')->willReturn(['identification_number' => 'page-cat-1']);

        ($this->handler)(new PageResyncMessage(cmsPageIds: ['layout-1']));

        $this->assertSame([
            ['type' => 'page.updated', 'data' => ['identification_number' => 'page-lp-1']],
            ['type' => 'page.deleted', 'data' => ['identification_number' => 'page-lp-2']],
            ['type' => 'page.updated', 'data' => ['identification_number' => 'page-cat-1']],
        ], $this->dispatchedEvents);

        $layoutFilters = array_filter(
            $capturedCriteria[0]->getFilters(),
            fn ($filter) => $filter instanceof EqualsAnyFilter && $filter->getField() === 'cmsPageId',
        );
        $this->assertSame(['layout-1'], array_values($layoutFilters)[0]->getValue());
    }

    public function testSkipsWhenPageSyncDisabled(): void
    {
        $config = $this->createMock(ConfigServiceInterface::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isSyncPagesEnabled')->willReturn(false);
        $this->landingPageRepository->expects($this->never())->method('search');

        $handler = new PageResyncMessageHandler($config, $this->syncService, $this->cmsPageFormatter, $this->landingPageRepository, $this->categoryRepository, $this->messageBus, $this->eventDispatcher, $this->createMock(LoggerInterface::class));
        $handler(new PageResyncMessage(landingPageIds: ['lp-1']));

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testDoesNothingForAnEmptyMessage(): void
    {
        $this->syncService->expects($this->never())->method('buildChannelContexts');

        ($this->handler)(new PageResyncMessage());
    }

    /**
     * @param list<LandingPageEntity> $pages
     */
    private function mockLandingPages(array $pages): void
    {
        $this->landingPageRepository->method('search')->willReturn($this->searchResult('landing_page', new LandingPageCollection($pages)));
    }

    /**
     * @param list<CategoryEntity> $categories
     */
    private function mockCategories(array $categories): void
    {
        $this->categoryRepository->method('search')->willReturn($this->searchResult('category', new CategoryCollection($categories)));
    }

    private function landingPage(string $id, bool $active = true): LandingPageEntity
    {
        $page = new LandingPageEntity();
        $page->setId($id);
        $page->setActive($active);

        return $page;
    }

    private function category(string $id, string $layoutType, bool $active = true): CategoryEntity
    {
        $cmsPage = new CmsPageEntity();
        $cmsPage->setId('layout-' . $id);
        $cmsPage->setType($layoutType);

        $category = new CategoryEntity();
        $category->setId($id);
        $category->setType('page');
        $category->setParentId('parent-1');
        $category->setActive($active);
        $category->setCmsPage($cmsPage);

        return $category;
    }

    private function searchResult(string $entity, object $collection): EntitySearchResult
    {
        return new EntitySearchResult($entity, $collection->count(), $collection, null, new Criteria(), Context::createDefaultContext());
    }
}
