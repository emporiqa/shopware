<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\CmsContentResolverInterface;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsBlock\CmsBlockCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsBlock\CmsBlockEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSection\CmsSectionCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsSection\CmsSectionEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\LandingPage\Aggregate\LandingPageTranslation\LandingPageTranslationCollection;
use Shopware\Core\Content\LandingPage\Aggregate\LandingPageTranslation\LandingPageTranslationEntity;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlCollection;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class CmsPageFormatterTest extends TestCase
{
    private CmsPageFormatter $formatter;

    /** @var array<string, array<int, array<string, string>>> */
    private array $defaultChannelContexts;

    protected function setUp(): void
    {
        $this->formatter = new CmsPageFormatter();

        $this->defaultChannelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
            ],
        ];
    }

    public function testFormatLandingPageBasicFormatting(): void
    {
        $landingPage = $this->createLandingPageMock('lp-001', 'Summer Sale', ['lang-en' => 'summer-sale']);

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('page-lp-001', $result['identification_number']);
        $this->assertSame([''], $result['channels']);
        $this->assertSame(['' => ['en' => 'Summer Sale']], $result['titles']);
        $this->assertSame(['' => ['en' => 'https://shop.example.com/summer-sale']], $result['links']);
        $this->assertSame(['' => ['en' => '']], $result['contents']);
        $this->assertArrayNotHasKey('sync_session_id', $result);
    }

    public function testFormatLandingPageWithSyncSessionId(): void
    {
        $landingPage = $this->createLandingPageMock('lp-002', 'Winter Sale', ['lang-en' => 'winter-sale']);

        $result = $this->formatter->formatLandingPage(
            $landingPage,
            $this->defaultChannelContexts,
            'sync-session-xyz',
        );

        $this->assertSame('page-lp-002', $result['identification_number']);
        $this->assertSame('sync-session-xyz', $result['sync_session_id']);
    }

    public function testFormatLandingPageLinksUseCanonicalSeoUrlNotUrlField(): void
    {
        // The url field ("de/versandrichtlinie") is not the storefront path; appending
        // it to the /de domain produced a 404. The canonical SEO URL is what resolves.
        $landingPage = $this->createLandingPageMock(
            'lp-shipping',
            'Versand',
            ['lang-en' => 'shipping-policy', 'lang-de' => 'de-versandrichtlinie'],
            url: 'de/versandrichtlinie',
        );

        $result = $this->formatter->formatLandingPage($landingPage, [
            '' => [
                ['languageCode' => 'en-GB', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'de-DE', 'domainUrl' => 'https://shop.example.com/de', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-de'],
            ],
        ]);

        $this->assertSame('https://shop.example.com/shipping-policy', $result['links']['']['en-GB']);
        $this->assertSame('https://shop.example.com/de/de-versandrichtlinie', $result['links']['']['de-DE']);
    }

    public function testFormatLandingPageHandlesTrailingSlashOnDomain(): void
    {
        $landingPage = $this->createLandingPageMock('lp-003', 'Page', ['lang-en' => 'offers/spring']);

        $result = $this->formatter->formatLandingPage($landingPage, [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com/', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
            ],
        ]);

        $this->assertSame('https://shop.example.com/offers/spring', $result['links']['']['en']);
    }

    public function testFormatLandingPageIgnoresNonCanonicalAndDeletedSeoUrls(): void
    {
        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('getId')->willReturn('lp-seo');
        $landingPage->method('getTranslation')->willReturnCallback(fn(string $f) => $f === 'name' ? 'SEO Page' : null);
        $landingPage->method('getName')->willReturn('SEO Page');
        $landingPage->method('getCmsPage')->willReturn(null);
        $landingPage->method('getTranslations')->willReturn(null);
        $landingPage->method('getSalesChannels')->willReturn($this->createSalesChannels(['sc-1']));
        $landingPage->method('getSeoUrls')->willReturn(new SeoUrlCollection([
            $this->createSeoUrl('sc-1', 'lang-en', 'old-path', canonical: false),
            $this->createSeoUrl('sc-1', 'lang-en', 'deleted-path', deleted: true),
            $this->createSeoUrl('sc-1', 'lang-en', 'current-path'),
        ]));

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('https://shop.example.com/current-path', $result['links']['']['en']);
    }

    public function testFormatLandingPageFallsBackToTechnicalRouteWithoutSeoUrl(): void
    {
        // SEO URLs may not be generated yet (queued indexing); the technical route
        // resolves in the storefront and redirects once they exist.
        $landingPage = $this->createLandingPageMock('lp-004', 'No SEO URL', []);

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('https://shop.example.com/landingPage/lp-004', $result['links']['']['en']);
    }

    public function testFormatLandingPageReturnsNullWhenNotAssignedToSyncedSalesChannel(): void
    {
        // The storefront only serves landing pages assigned to the sales channel,
        // an unassigned page would sync with a dead link.
        $landingPage = $this->createLandingPageMock('lp-005', 'Unassigned', ['lang-en' => 'unassigned'], salesChannelIds: []);

        $this->assertNull($this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts));
    }

    public function testFormatLandingPageUsesTechnicalRouteForLanguageWithoutSeoUrl(): void
    {
        $landingPage = $this->createLandingPageMock('lp-lang', 'English Only', ['lang-en' => 'english-only']);

        $result = $this->formatter->formatLandingPage($landingPage, [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'de', 'domainUrl' => 'https://shop.de', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-de'],
            ],
        ]);

        $this->assertSame('https://shop.com/english-only', $result['links']['']['en']);
        $this->assertSame('https://shop.de/landingPage/lp-lang', $result['links']['']['de']);
    }

    public function testFormatLandingPagePrefersContextWithSeoUrlThenHttps(): void
    {
        $landingPage = $this->createLandingPageMock('lp-pref', 'Pref', ['lang-en' => 'pref'], salesChannelIds: ['sc-1', 'sc-2']);

        $result = $this->formatter->formatLandingPage($landingPage, [
            '' => [
                // sc-2 is https but has no SEO URL; sc-1 has the SEO URL but only an http domain
                ['languageCode' => 'en', 'domainUrl' => 'https://shop2.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-2', 'languageId' => 'lang-en'],
                ['languageCode' => 'en', 'domainUrl' => 'http://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'USD', 'currencyId' => 'curr-usd', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
            ],
        ]);

        $this->assertSame('https://shop.com/pref', $result['links']['']['en']);
    }

    public function testFormatLandingPageFallbackName(): void
    {
        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('getId')->willReturn('lp-006');
        $landingPage->method('getTranslation')->willReturn(null);
        $landingPage->method('getName')->willReturn('Fallback Name');
        $landingPage->method('getUrl')->willReturn('page');
        $landingPage->method('getCmsPage')->willReturn(null);
        $landingPage->method('getTranslations')->willReturn(null);
        $landingPage->method('getSalesChannels')->willReturn($this->createSalesChannels(['sc-1']));
        $landingPage->method('getSeoUrls')->willReturn(new SeoUrlCollection([$this->createSeoUrl('sc-1', 'lang-en', 'page')]));

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('Fallback Name', $result['titles']['']['en']);
    }

    public function testFormatLandingPageUsesPerLanguageSlotConfigOverride(): void
    {
        // A landing page's editable text lives in its own translatable slotConfig
        // override (keyed by slot id), not in the shared CMS layout. The formatter
        // must resolve it per language so en/de content differs.
        $slot = $this->createMock(CmsSlotEntity::class);
        $slot->method('getId')->willReturn('slot-1');
        $slot->method('getConfig')->willReturn(null);
        $slot->method('getData')->willReturn(null);
        $slot->method('getTranslations')->willReturn(null);

        $block = $this->createMock(CmsBlockEntity::class);
        $block->method('getSlots')->willReturn(new CmsSlotCollection([$slot]));

        $section = $this->createMock(CmsSectionEntity::class);
        $section->method('getBlocks')->willReturn(new CmsBlockCollection([$block]));

        $cmsPage = $this->createMock(CmsPageEntity::class);
        $cmsPage->method('getSections')->willReturn(new CmsSectionCollection([$section]));

        $transEn = $this->createMock(LandingPageTranslationEntity::class);
        $transEn->method('getUniqueIdentifier')->willReturn('t-en');
        $transEn->method('getLanguageId')->willReturn('lang-en');
        $transEn->method('getSlotConfig')->willReturn(['slot-1' => ['content' => ['value' => 'English body', 'source' => 'static']]]);

        $transDe = $this->createMock(LandingPageTranslationEntity::class);
        $transDe->method('getUniqueIdentifier')->willReturn('t-de');
        $transDe->method('getLanguageId')->willReturn('lang-de');
        $transDe->method('getSlotConfig')->willReturn(['slot-1' => ['content' => ['value' => 'German body', 'source' => 'static']]]);

        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('getId')->willReturn('lp-slot');
        $landingPage->method('getName')->willReturn('Slot Page');
        $landingPage->method('getTranslation')->willReturnCallback(fn(string $f) => $f === 'name' ? 'Slot Page' : null);
        $landingPage->method('getCmsPage')->willReturn($cmsPage);
        $landingPage->method('getTranslations')->willReturn(new LandingPageTranslationCollection([$transEn, $transDe]));
        $landingPage->method('getSalesChannels')->willReturn($this->createSalesChannels(['sc-1']));
        $landingPage->method('getSeoUrls')->willReturn(new SeoUrlCollection([
            $this->createSeoUrl('sc-1', 'lang-en', 'slot-page'),
            $this->createSeoUrl('sc-1', 'lang-de', 'slot-seite'),
        ]));

        $channelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'de', 'domainUrl' => 'https://shop.de', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-de'],
            ],
        ];

        $result = $this->formatter->formatLandingPage($landingPage, $channelContexts);

        $this->assertSame('English body', $result['contents']['']['en']);
        $this->assertSame('German body', $result['contents']['']['de']);
    }

    public function testFormatLandingPageUsesStorefrontResolvedContent(): void
    {
        $resolver = $this->createMock(CmsContentResolverInterface::class);
        $resolver->expects($this->once())
            ->method('resolveLandingPageContent')
            ->with('lp-faq', 'sc-1', 'lang-en', 'curr-eur')
            ->willReturn('<p>How long does shipping take?</p>');

        $formatter = new CmsPageFormatter($resolver);
        $landingPage = $this->createLandingPageMock('lp-faq', 'FAQ', ['lang-en' => 'faq'], metaDescription: 'Meta');

        $result = $formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('<p>How long does shipping take?</p>', $result['contents']['']['en']);
    }

    public function testFormatLandingPageFallsBackWhenResolverCannotResolve(): void
    {
        $resolver = $this->createMock(CmsContentResolverInterface::class);
        $resolver->method('resolveLandingPageContent')->willReturn(null);

        $formatter = new CmsPageFormatter($resolver);
        $landingPage = $this->createLandingPageMock('lp-null', 'Page', ['lang-en' => 'page'], metaDescription: 'Stored meta description');

        $result = $formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('Stored meta description', $result['contents']['']['en']);
    }

    public function testFormatPageDeleteReturnsSingleEntry(): void
    {
        $result = $this->formatter->formatPageDelete('page-del-001');

        $this->assertCount(1, $result);
        $this->assertSame('page-page-del-001', $result[0]['identification_number']);
        $this->assertArrayNotHasKey('language', $result[0]);
    }

    public function testFormatLandingPageMultiLanguage(): void
    {
        $landingPage = $this->createLandingPageMock('lp-multi', 'Multi Page', ['lang-en' => 'multi-page', 'lang-de' => 'mehrsprachige-seite']);

        $channelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'de', 'domainUrl' => 'https://shop.de', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-de'],
            ],
        ];

        $result = $this->formatter->formatLandingPage($landingPage, $channelContexts);

        $this->assertArrayHasKey('en', $result['titles']['']);
        $this->assertArrayHasKey('de', $result['titles']['']);
        $this->assertSame('https://shop.com/multi-page', $result['links']['']['en']);
        $this->assertSame('https://shop.de/mehrsprachige-seite', $result['links']['']['de']);
    }

    public function testFormatLandingPageDoesNotLeakDefaultLanguageMetaTitleIntoOtherLanguages(): void
    {
        $transEn = $this->createMock(LandingPageTranslationEntity::class);
        $transEn->method('getUniqueIdentifier')->willReturn('t-en');
        $transEn->method('getLanguageId')->willReturn('lang-en');
        $transEn->method('getMetaTitle')->willReturn('English SEO title');
        $transEn->method('getName')->willReturn('FAQ');
        $transEn->method('getSlotConfig')->willReturn(null);

        $transDe = $this->createMock(LandingPageTranslationEntity::class);
        $transDe->method('getUniqueIdentifier')->willReturn('t-de');
        $transDe->method('getLanguageId')->willReturn('lang-de');
        $transDe->method('getMetaTitle')->willReturn(null);
        $transDe->method('getName')->willReturn('FAQ - Häufig gestellte Fragen');
        $transDe->method('getSlotConfig')->willReturn(null);

        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('getId')->willReturn('lp-title');
        // The entity was loaded in the default language: its resolved metaTitle is the English one
        $landingPage->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'metaTitle' => 'English SEO title', 'name' => 'FAQ', default => null });
        $landingPage->method('getName')->willReturn('FAQ');
        $landingPage->method('getCmsPage')->willReturn(null);
        $landingPage->method('getTranslations')->willReturn(new LandingPageTranslationCollection([$transEn, $transDe]));
        $landingPage->method('getSalesChannels')->willReturn($this->createSalesChannels(['sc-1']));
        $landingPage->method('getSeoUrls')->willReturn(new SeoUrlCollection([
            $this->createSeoUrl('sc-1', 'lang-en', 'faq'),
            $this->createSeoUrl('sc-1', 'lang-de', 'de-faq'),
        ]));

        $result = $this->formatter->formatLandingPage($landingPage, [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'de', 'domainUrl' => 'https://shop.de', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-de'],
            ],
        ]);

        $this->assertSame('English SEO title', $result['titles']['']['en']);
        $this->assertSame('FAQ - Häufig gestellte Fragen', $result['titles']['']['de']);
    }

    public function testFormatLandingPagePrefersMetaTitleOverName(): void
    {
        $landingPage = $this->createLandingPageMock('lp-meta', 'Internal Name', ['lang-en' => 'meta-page'], metaTitle: 'SEO Meta Title');

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('SEO Meta Title', $result['titles']['']['en']);
    }

    public function testFormatLandingPageFallsBackToNameWhenMetaTitleEmpty(): void
    {
        $landingPage = $this->createLandingPageMock('lp-no-meta', 'Internal Name', ['lang-en' => 'no-meta-page']);

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('Internal Name', $result['titles']['']['en']);
    }

    public function testFormatLandingPageFallsBackToMetaDescriptionWhenContentEmpty(): void
    {
        $landingPage = $this->createLandingPageMock(
            'lp-meta-desc',
            'Banner Page',
            ['lang-en' => 'banner-page'],
            metaDescription: 'A page built purely from banners.',
        );

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('A page built purely from banners.', $result['contents']['']['en']);
    }

    public function testFormatShopPageUsesStorefrontResolvedContent(): void
    {
        $resolver = $this->createMock(CmsContentResolverInterface::class);
        $resolver->expects($this->once())
            ->method('resolveCategoryContent')
            ->with('cat-faq', 'sc-1', 'lang-en', 'curr-eur')
            ->willReturn('Resolved category content');

        $category = $this->createMock(CategoryEntity::class);
        $category->method('getId')->willReturn('cat-faq');
        $category->method('getParentId')->willReturn('parent-1');
        $category->method('getPath')->willReturn('|root-nav|parent-1|');
        $category->method('getSeoUrls')->willReturn(new SeoUrlCollection([$this->createSeoUrl('sc-1', 'lang-en', 'faq')]));
        $category->method('getCmsPage')->willReturn(null);
        $category->method('getTranslations')->willReturn(null);
        $category->method('getTranslation')->willReturnCallback(fn(string $field) => $field === 'name' ? 'FAQ' : null);
        $category->method('getName')->willReturn('FAQ');

        $result = (new CmsPageFormatter($resolver))->formatShopPage($category, $this->defaultChannelContexts);

        $this->assertSame('Resolved category content', $result['contents']['']['en']);
        $this->assertSame('https://shop.example.com/faq', $result['links']['']['en']);
    }

    public function testFormatShopPageUsesTechnicalRouteWhenInTreeWithoutSeoUrl(): void
    {
        $category = $this->createCategoryMock('cat-new', path: '|root-nav|parent|');
        $category->method('getSeoUrls')->willReturn(new SeoUrlCollection([]));

        $result = $this->formatter->formatShopPage($category, $this->treeChannelContexts());

        $this->assertSame('https://shop.example.com/navigation/cat-new', $result['links']['']['en']);
    }

    public function testFormatShopPageReturnsNullOutsideEveryChannelTree(): void
    {
        $category = $this->createCategoryMock('cat-other', path: '|some-other-root|');
        $category->method('getSeoUrls')->willReturn(new SeoUrlCollection([$this->createSeoUrl('sc-1', 'lang-en', 'other')]));

        $this->assertNull($this->formatter->formatShopPage($category, $this->treeChannelContexts()));
    }

    public function testFormatShopPageReturnsNullForTreeRoots(): void
    {
        $category = $this->createCategoryMock('root-nav', path: null, parentId: null);
        $category->method('getSeoUrls')->willReturn(new SeoUrlCollection([]));

        $this->assertNull($this->formatter->formatShopPage($category, $this->treeChannelContexts()));
    }

    public function testFormatShopPageIncludesFooterAndServiceTrees(): void
    {
        $category = $this->createCategoryMock('cat-footer', path: '|root-footer|');
        $category->method('getSeoUrls')->willReturn(new SeoUrlCollection([$this->createSeoUrl('sc-1', 'lang-en', 'imprint')]));

        $result = $this->formatter->formatShopPage($category, $this->treeChannelContexts());

        $this->assertSame('https://shop.example.com/imprint', $result['links']['']['en']);
    }

    public function testFormatShopPageTreatsContextsWithoutTreeInfoAsReachable(): void
    {
        $category = $this->createCategoryMock('cat-legacy', path: '|unknown|');
        $category->method('getSeoUrls')->willReturn(new SeoUrlCollection([]));

        $result = $this->formatter->formatShopPage($category, $this->defaultChannelContexts);

        $this->assertSame('https://shop.example.com/navigation/cat-legacy', $result['links']['']['en']);
    }

    public function testFormatShopPagePrefersMetaTitleOverName(): void
    {
        $seoUrl = $this->createMock(SeoUrlEntity::class);
        $seoUrl->method('getUniqueIdentifier')->willReturn('seo-1');
        $seoUrl->method('getIsDeleted')->willReturn(false);
        $seoUrl->method('getIsCanonical')->willReturn(true);
        $seoUrl->method('getSalesChannelId')->willReturn('sc-1');
        $seoUrl->method('getLanguageId')->willReturn('lang-en');
        $seoUrl->method('getSeoPathInfo')->willReturn('shop-category');

        $category = $this->createMock(CategoryEntity::class);
        $category->method('getId')->willReturn('cat-meta');
        $category->method('getParentId')->willReturn('parent-1');
        $category->method('getPath')->willReturn('|root-nav|parent-1|');
        $category->method('getSeoUrls')->willReturn(new SeoUrlCollection([$seoUrl]));
        $category->method('getCmsPage')->willReturn(null);
        $category->method('getTranslations')->willReturn(null);
        $category->method('getTranslation')->willReturnCallback(fn(string $field) => match ($field) {
            'name' => 'Internal Category Name',
            'metaTitle' => 'SEO Category Title',
            default => null,
        });
        $category->method('getName')->willReturn('Internal Category Name');

        $result = $this->formatter->formatShopPage($category, $this->defaultChannelContexts);

        $this->assertNotNull($result);
        $this->assertSame('SEO Category Title', $result['titles']['']['en']);
    }

    /**
     * @return LandingPageEntity&MockObject
     */
    /**
     * @param array<string, string> $seoPaths languageId => canonical SEO path in sales channel sc-1
     * @param list<string> $salesChannelIds
     */
    private function createLandingPageMock(
        string $id,
        string $name,
        array $seoPaths,
        string $metaTitle = '',
        string $metaDescription = '',
        string $url = 'landing-page',
        array $salesChannelIds = ['sc-1'],
    ): LandingPageEntity&MockObject {
        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('getId')->willReturn($id);
        $landingPage->method('getTranslation')->willReturnCallback(fn(string $field) => match ($field) {
            'name' => $name,
            'metaTitle' => $metaTitle !== '' ? $metaTitle : null,
            'metaDescription' => $metaDescription !== '' ? $metaDescription : null,
            default => null,
        });
        $landingPage->method('getName')->willReturn($name);
        $landingPage->method('getUrl')->willReturn($url);
        $landingPage->method('getCmsPage')->willReturn(null);
        $landingPage->method('getTranslations')->willReturn(null);
        $landingPage->method('getSalesChannels')->willReturn($this->createSalesChannels($salesChannelIds));

        $seoUrls = [];
        foreach ($seoPaths as $languageId => $path) {
            $seoUrls[] = $this->createSeoUrl('sc-1', $languageId, $path);
        }
        $landingPage->method('getSeoUrls')->willReturn(new SeoUrlCollection($seoUrls));

        return $landingPage;
    }

    private function createCategoryMock(string $id, ?string $path, ?string $parentId = 'parent-1'): CategoryEntity&MockObject
    {
        $category = $this->createMock(CategoryEntity::class);
        $category->method('getId')->willReturn($id);
        $category->method('getParentId')->willReturn($parentId);
        $category->method('getPath')->willReturn($path);
        $category->method('getCmsPage')->willReturn(null);
        $category->method('getTranslations')->willReturn(null);
        $category->method('getTranslation')->willReturnCallback(fn(string $field) => $field === 'name' ? 'Category ' . $id : null);
        $category->method('getName')->willReturn('Category ' . $id);

        return $category;
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    private function treeChannelContexts(): array
    {
        return [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en', 'navigationCategoryId' => 'root-nav', 'footerCategoryId' => 'root-footer', 'serviceCategoryId' => ''],
            ],
        ];
    }

    private function createSeoUrl(
        string $salesChannelId,
        string $languageId,
        string $path,
        bool $canonical = true,
        bool $deleted = false,
    ): SeoUrlEntity {
        $seoUrl = new SeoUrlEntity();
        $seoUrl->setId(md5($salesChannelId . $languageId . $path));
        $seoUrl->setSalesChannelId($salesChannelId);
        $seoUrl->setLanguageId($languageId);
        $seoUrl->setSeoPathInfo($path);
        $seoUrl->setIsCanonical($canonical);
        $seoUrl->setIsDeleted($deleted);

        return $seoUrl;
    }

    /**
     * @param list<string> $ids
     */
    private function createSalesChannels(array $ids): SalesChannelCollection
    {
        $salesChannels = [];
        foreach ($ids as $id) {
            $salesChannel = new SalesChannelEntity();
            $salesChannel->setId($id);
            $salesChannels[] = $salesChannel;
        }

        return new SalesChannelCollection($salesChannels);
    }
}
