<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\CmsContentResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsBlock\CmsBlockCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsBlock\CmsBlockEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSection\CmsSectionCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsSection\CmsSectionEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\CmsPageCollection;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Cms\SalesChannel\SalesChannelCmsPageLoaderInterface;
use Shopware\Core\Content\Cms\SalesChannel\Struct\HtmlStruct;
use Shopware\Core\Content\Cms\SalesChannel\Struct\ImageStruct;
use Shopware\Core\Content\Cms\SalesChannel\Struct\TextStruct;
use Shopware\Core\Content\LandingPage\LandingPageCollection;
use Shopware\Core\Content\LandingPage\LandingPageDefinition;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CmsContentResolverTest extends TestCase
{
    private SalesChannelCmsPageLoaderInterface&MockObject $cmsPageLoader;
    private AbstractSalesChannelContextFactory&MockObject $contextFactory;
    private SalesChannelRepository&MockObject $categoryRepository;
    private SalesChannelRepository&MockObject $landingPageRepository;
    private LoggerInterface&MockObject $logger;
    private CmsContentResolver $resolver;

    protected function setUp(): void
    {
        $this->cmsPageLoader = $this->createMock(SalesChannelCmsPageLoaderInterface::class);
        $this->contextFactory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $this->categoryRepository = $this->createMock(SalesChannelRepository::class);
        $this->landingPageRepository = $this->createMock(SalesChannelRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());
        $this->contextFactory->method('create')->willReturn($salesChannelContext);

        $this->resolver = new CmsContentResolver(
            $this->cmsPageLoader,
            $this->contextFactory,
            $this->categoryRepository,
            $this->landingPageRepository,
            $this->createMock(CategoryDefinition::class),
            $this->createMock(LandingPageDefinition::class),
            $this->logger,
        );
    }

    public function testCategoryContentComesFromResolvedTextAndHtmlSlots(): void
    {
        $this->mockCategory('cat-1', 'layout-1');
        $this->mockLoadedPage([
            $this->slot('text', data: $this->textStruct('<p>Mapped category description</p>')),
            $this->slot('image', data: new ImageStruct()),
            $this->slot('html', data: $this->htmlStruct('<div>Custom HTML text</div>')),
            $this->slot('product-slider', config: ['title' => ['source' => 'static', 'value' => 'Our best selling products']]),
        ]);

        $content = $this->resolver->resolveCategoryContent('cat-1', 'sc-1', 'lang-en', 'curr-eur');

        // Slider titles are visible text on the page, so they are included
        $this->assertSame("<p>Mapped category description</p>\n\n<div>Custom HTML text</div>\n\nOur best selling products", $content);
    }

    public function testCustomElementTextIsCollectedFromConfig(): void
    {
        // Third-party elements (e.g. FAQ accordions) often have no PHP resolver,
        // their text only exists in the slot config.
        $this->mockCategory('cat-faq', 'layout-faq');
        $this->mockLoadedPage([
            $this->slot('acme-faq-accordion', config: [
                'items' => ['source' => 'static', 'value' => [
                    ['question' => 'How long does shipping take?', 'answer' => '<p>2-3 days.</p>', 'iconColor' => '#ffffff'],
                    ['question' => 'Can I return an item?', 'answer' => '<p>Within 30 days.</p>', 'mediaId' => '0192d4d1a6b373b0a1b2c3d4e5f60718'],
                ]],
                'displayMode' => ['source' => 'static', 'value' => 'standard'],
                'intro' => ['source' => 'mapped', 'value' => 'category.description text here'],
                'layout' => ['source' => 'static', 'value' => 'boxed'],
            ]),
        ]);

        $content = $this->resolver->resolveCategoryContent('cat-faq', 'sc-1', 'lang-en', 'curr-eur');

        $this->assertSame(
            "How long does shipping take?\n\n<p>2-3 days.</p>\n\nCan I return an item?\n\n<p>Within 30 days.</p>",
            $content,
        );
    }

    public function testCategorySlotConfigOverrideIsPassedToLoader(): void
    {
        $slotConfig = ['slot-1' => ['content' => ['source' => 'static', 'value' => 'Override']]];
        $this->mockCategory('cat-2', 'layout-2', $slotConfig);

        $capturedConfig = null;
        $capturedPageIds = null;
        $this->cmsPageLoader->method('load')->willReturnCallback(
            function ($request, Criteria $criteria, $context, ?array $config) use (&$capturedConfig, &$capturedPageIds) {
                $capturedConfig = $config;
                $capturedPageIds = $criteria->getIds();

                return $this->pageResult([$this->slot('text', data: $this->textStruct('Override'))]);
            },
        );

        $this->assertSame('Override', $this->resolver->resolveCategoryContent('cat-2', 'sc-1', 'lang-en', 'curr-eur'));
        $this->assertSame($slotConfig, $capturedConfig);
        $this->assertSame(['layout-2'], $capturedPageIds);
    }

    public function testLandingPageContentUsesTranslatedSlotConfig(): void
    {
        $landingPage = new LandingPageEntity();
        $landingPage->setId('lp-1');
        $landingPage->setCmsPageId('layout-lp');
        $landingPage->setTranslated(['slotConfig' => ['slot-1' => ['content' => ['value' => 'Hi']]]]);
        $this->landingPageRepository->method('search')->willReturn($this->searchResult('landing_page', new LandingPageCollection([$landingPage])));

        $capturedConfig = null;
        $this->cmsPageLoader->method('load')->willReturnCallback(
            function ($request, $criteria, $context, ?array $config) use (&$capturedConfig) {
                $capturedConfig = $config;

                return $this->pageResult([$this->slot('text', data: $this->textStruct('Landing page text'))]);
            },
        );

        $this->assertSame('Landing page text', $this->resolver->resolveLandingPageContent('lp-1', 'sc-1', 'lang-en', 'curr-eur'));
        $this->assertSame(['slot-1' => ['content' => ['value' => 'Hi']]], $capturedConfig);
    }

    public function testReturnsNullWhenCategoryHasNoLayout(): void
    {
        $this->mockCategory('cat-3', null);
        $this->cmsPageLoader->expects($this->never())->method('load');

        $this->assertNull($this->resolver->resolveCategoryContent('cat-3', 'sc-1', 'lang-en', 'curr-eur'));
    }

    public function testReturnsNullAndLogsWhenLoaderFails(): void
    {
        $this->mockCategory('cat-4', 'layout-4');
        $this->cmsPageLoader->method('load')->willThrowException(new \RuntimeException('resolver exploded'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->resolver->resolveCategoryContent('cat-4', 'sc-1', 'lang-en', 'curr-eur'));
    }

    public function testReturnsNullWhenSalesChannelContextCannotBeCreated(): void
    {
        $factory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('language not assigned'));
        $this->logger->expects($this->once())->method('warning');
        $this->categoryRepository->expects($this->never())->method('search');

        $resolver = new CmsContentResolver(
            $this->cmsPageLoader,
            $factory,
            $this->categoryRepository,
            $this->landingPageRepository,
            $this->createMock(CategoryDefinition::class),
            $this->createMock(LandingPageDefinition::class),
            $this->logger,
        );

        $this->assertNull($resolver->resolveCategoryContent('cat-5', 'sc-1', 'lang-xx', 'curr-eur'));
        // The failure is cached for the channel/language/currency, no second attempt or log.
        $this->assertNull($resolver->resolveCategoryContent('cat-6', 'sc-1', 'lang-xx', 'curr-eur'));
    }

    public function testSalesChannelContextIsCreatedOncePerChannelLanguageAndCurrency(): void
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $factory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $factory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function (string $token, string $salesChannelId, array $options) use ($salesChannelContext) {
                $this->assertSame('sc-1', $salesChannelId);
                $this->assertArrayHasKey(SalesChannelContextService::LANGUAGE_ID, $options);
                $this->assertSame('curr-eur', $options[SalesChannelContextService::CURRENCY_ID]);

                return $salesChannelContext;
            });

        $this->mockCategory('cat-7', 'layout-7');
        $this->mockLoadedPage([$this->slot('text', data: $this->textStruct('Text'))]);

        $resolver = new CmsContentResolver(
            $this->cmsPageLoader,
            $factory,
            $this->categoryRepository,
            $this->landingPageRepository,
            $this->createMock(CategoryDefinition::class),
            $this->createMock(LandingPageDefinition::class),
            $this->logger,
        );

        $resolver->resolveCategoryContent('cat-7', 'sc-1', 'lang-en', 'curr-eur');
        $resolver->resolveCategoryContent('cat-7', 'sc-1', 'lang-en', 'curr-eur');
        $resolver->resolveCategoryContent('cat-7', 'sc-1', 'lang-de', 'curr-eur');
    }

    /**
     * @param array<string, mixed>|null $slotConfig
     */
    private function mockCategory(string $id, ?string $cmsPageId, ?array $slotConfig = null): void
    {
        $category = new CategoryEntity();
        $category->setId($id);
        if ($cmsPageId !== null) {
            $category->setCmsPageId($cmsPageId);
        }
        $category->setTranslated(['slotConfig' => $slotConfig]);

        $this->categoryRepository->method('search')->willReturn($this->searchResult('category', new CategoryCollection([$category])));
    }

    /**
     * @param list<CmsSlotEntity> $slots
     */
    private function mockLoadedPage(array $slots): void
    {
        $this->cmsPageLoader->method('load')->willReturnCallback(fn () => $this->pageResult($slots));
    }

    /**
     * @param list<CmsSlotEntity> $slots
     * @return EntitySearchResult<CmsPageCollection>
     */
    private function pageResult(array $slots): EntitySearchResult
    {
        $block = new CmsBlockEntity();
        $block->setId('block-1');
        $block->setSlots(new CmsSlotCollection($slots));

        $section = new CmsSectionEntity();
        $section->setId('section-1');
        $section->setBlocks(new CmsBlockCollection([$block]));

        $page = new CmsPageEntity();
        $page->setId('page-1');
        $page->setSections(new CmsSectionCollection([$section]));

        return $this->searchResult('cms_page', new CmsPageCollection([$page]));
    }

    /**
     * @param array<string, mixed>|null $config
     */
    private function slot(string $type, ?object $data = null, ?array $config = null): CmsSlotEntity
    {
        static $counter = 0;

        $slot = new CmsSlotEntity();
        $slot->setId('slot-' . ++$counter);
        $slot->setType($type);
        $slot->setSlot('content');
        if ($config !== null) {
            $slot->setConfig($config);
        }
        if ($data !== null) {
            $slot->setData($data);
        }

        return $slot;
    }

    private function textStruct(string $content): TextStruct
    {
        $struct = new TextStruct();
        $struct->setContent($content);

        return $struct;
    }

    private function htmlStruct(string $content): HtmlStruct
    {
        $struct = new HtmlStruct();
        $struct->setContent($content);

        return $struct;
    }

    private function searchResult(string $entity, object $collection): EntitySearchResult
    {
        return new EntitySearchResult($entity, $collection->count(), $collection, null, new Criteria(), Context::createDefaultContext());
    }
}
