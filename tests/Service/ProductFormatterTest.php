<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\ProductFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceCollection;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;

class ProductFormatterTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private ProductFormatter $formatter;

    /** @var array<string, array<int, array<string, string>>> */
    private array $defaultChannelContexts;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->config->method('getBrandAttribute')->willReturn('');

        $this->formatter = new ProductFormatter($this->config);

        $this->defaultChannelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
            ],
        ];
    }

    public function testFormatProductWithSimpleProduct(): void
    {
        $product = $this->createSimpleProduct('prod-001', 'Test Product', 'SKU-001');

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertCount(1, $result);

        $parent = $result[0];
        $this->assertSame('product-prod-001', $parent['identification_number']);
        $this->assertSame('SKU-001', $parent['sku']);
        $this->assertSame([''], $parent['channels']);
        $this->assertSame(['' => ['en' => 'Test Product']], $parent['names']);
        $this->assertFalse($parent['is_parent']);
        $this->assertNull($parent['parent_sku']);
        $this->assertInstanceOf(\stdClass::class, $parent['variation_attributes']);
        $this->assertArrayNotHasKey('sync_session_id', $parent);
    }

    public function testFormatProductIncludesSyncSessionId(): void
    {
        $product = $this->createSimpleProduct('prod-002', 'Product 2', 'SKU-002');

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts, 'session-abc');

        $this->assertCount(1, $result);
        $this->assertSame('session-abc', $result[0]['sync_session_id']);
    }

    public function testFormatProductWithVariants(): void
    {
        $variant1 = $this->createVariantProduct('var-001', 'Variant 1', 'VAR-SKU-001');
        $variant2 = $this->createVariantProduct('var-002', 'Variant 2', 'VAR-SKU-002');

        $children = new ProductCollection([$variant1, $variant2]);

        $product = $this->createSimpleProduct('parent-001', 'Parent Product', 'PARENT-SKU', children: $children);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertCount(3, $result);

        $parent = $result[0];
        $this->assertSame('product-parent-001', $parent['identification_number']);
        $this->assertTrue($parent['is_parent']);
        $this->assertNull($parent['parent_sku']);

        $var1 = $result[1];
        $this->assertSame('variation-var-001', $var1['identification_number']);
        $this->assertFalse($var1['is_parent']);
        $this->assertSame('PARENT-SKU', $var1['parent_sku']);

        $var2 = $result[2];
        $this->assertSame('variation-var-002', $var2['identification_number']);
        $this->assertFalse($var2['is_parent']);
        $this->assertSame('PARENT-SKU', $var2['parent_sku']);
    }

    public function testFormatProductAvailabilityForSimpleProduct(): void
    {
        $product = $this->createSimpleProduct('prod-avail', 'Available Product', 'SKU-A', available: true, availableStock: 10);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => 'available'], $result[0]['availability_statuses']);
        $this->assertSame(['' => 10], $result[0]['stock_quantities']);
    }

    public function testFormatProductOutOfStockForSimpleProduct(): void
    {
        $product = $this->createSimpleProduct('prod-oos', 'Out of Stock', 'SKU-OOS', available: false, availableStock: 0, isCloseout: true);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => 'out_of_stock'], $result[0]['availability_statuses']);
        $this->assertSame(['' => 0], $result[0]['stock_quantities']);
    }

    public function testFormatProductBackorderForSimpleProductWithoutCloseout(): void
    {
        $product = $this->createSimpleProduct('prod-bo', 'Backorder', 'SKU-BO', available: false, availableStock: 0);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => 'backorder'], $result[0]['availability_statuses']);
        $this->assertSame(['' => 0], $result[0]['stock_quantities']);
    }

    public function testFormatProductAvailableIgnoresCloseoutWhenStockPositive(): void
    {
        $product = $this->createSimpleProduct('prod-avc', 'Available Closeout', 'SKU-AVC', availableStock: 3, isCloseout: true);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => 'available'], $result[0]['availability_statuses']);
    }

    public function testParentAggregatesChildAvailability(): void
    {
        // One child out of stock (closeout), one available → parent available, stock summed
        $closeoutChild = $this->createVariantProduct('var-agg-1', 'Agg 1', 'AGG-1', stock: 0, isCloseout: true);
        $availableChild = $this->createVariantProduct('var-agg-2', 'Agg 2', 'AGG-2', stock: 7);

        $children = new ProductCollection([$closeoutChild, $availableChild]);
        $product = $this->createSimpleProduct('parent-agg', 'Agg Parent', 'AGG-P', children: $children);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => 'available'], $result[0]['availability_statuses']);
        $this->assertSame(['' => 7], $result[0]['stock_quantities']);
    }

    public function testParentBackorderWhenNoChildAvailable(): void
    {
        $closeoutChild = $this->createVariantProduct('var-agg-3', 'Agg 3', 'AGG-3', stock: 0, isCloseout: true);
        $backorderChild = $this->createVariantProduct('var-agg-4', 'Agg 4', 'AGG-4', stock: 0, isCloseout: false);

        $children = new ProductCollection([$closeoutChild, $backorderChild]);
        $product = $this->createSimpleProduct('parent-agg-bo', 'Agg BO Parent', 'AGG-BOP', children: $children);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => 'backorder'], $result[0]['availability_statuses']);
        $this->assertSame(['' => 0], $result[0]['stock_quantities']);
    }

    public function testParentOutOfStockWhenAllChildrenCloseout(): void
    {
        $child1 = $this->createVariantProduct('var-agg-5', 'Agg 5', 'AGG-5', stock: 0, isCloseout: true);
        $child2 = $this->createVariantProduct('var-agg-6', 'Agg 6', 'AGG-6', stock: 0, isCloseout: true);

        $children = new ProductCollection([$child1, $child2]);
        $product = $this->createSimpleProduct('parent-agg-oos', 'Agg OOS Parent', 'AGG-OOSP', children: $children);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => 'out_of_stock'], $result[0]['availability_statuses']);
    }

    public function testParentAggregationSkipsInactiveChildren(): void
    {
        $inactiveChild = $this->createVariantProduct('var-inact', 'Inactive', 'INACT', stock: 100, active: false);
        $closeoutChild = $this->createVariantProduct('var-act', 'Active', 'ACT', stock: 0, isCloseout: true);

        $children = new ProductCollection([$inactiveChild, $closeoutChild]);
        $product = $this->createSimpleProduct('parent-inact', 'Inact Parent', 'INACT-P', children: $children);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => 'out_of_stock'], $result[0]['availability_statuses']);
        $this->assertSame(['' => 0], $result[0]['stock_quantities']);
    }

    public function testFormatProductSkipsInactiveChildrenInVariationPayloads(): void
    {
        $activeChild = $this->createVariantProduct('var-visible', 'Visible', 'VIS-1', active: true);
        $inactiveChild = $this->createVariantProduct('var-hidden', 'Hidden', 'HID-1', active: false);

        $children = new ProductCollection([$activeChild, $inactiveChild]);
        $product = $this->createSimpleProduct('parent-mixed-active', 'Mixed Parent', 'MIXED-P', children: $children);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        // Parent + only the active variant — the inactive one must not ship.
        $this->assertCount(2, $result);
        $this->assertSame('variation-var-visible', $result[1]['identification_number']);
    }

    public function testVariantCloseoutInheritsFromParent(): void
    {
        // Variant has raw isCloseout null → inherits parent's closeout=true → out_of_stock at 0 stock
        $variant = $this->createVariantProduct('var-inherit', 'Inherit', 'INH-1', stock: 0, isCloseout: null);

        $children = new ProductCollection([$variant]);
        $product = $this->createSimpleProduct('parent-inherit', 'Inherit Parent', 'INH-P', children: $children, isCloseout: true);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => 'out_of_stock'], $result[1]['availability_statuses']);
    }

    public function testFormatProductUrlWithoutSeoUrls(): void
    {
        $product = $this->createSimpleProduct('prod-url', 'URL Product', 'SKU-URL');

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => ['en' => 'https://shop.example.com/detail/prod-url']], $result[0]['links']);
    }

    public function testFormatProductDeleteReturnsSingleEntry(): void
    {
        $result = $this->formatter->formatProductDelete('prod-delete-001');

        $this->assertCount(1, $result);
        $this->assertSame('product-prod-delete-001', $result[0]['identification_number']);
        $this->assertArrayNotHasKey('language', $result[0]);
    }

    public function testFormatProductDefaultCategoryAndBrand(): void
    {
        $product = $this->createSimpleProduct('prod-defaults', 'Defaults Product', 'SKU-DEF');

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => ['en' => []]], $result[0]['categories']);
        $this->assertSame(['' => ''], $result[0]['brands']);
    }

    public function testFormatProductEmptyAttributesBecomesEmptyObject(): void
    {
        $product = $this->createSimpleProduct('prod-attr', 'Attr Product', 'SKU-ATTR');

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertInstanceOf(\stdClass::class, $result[0]['attributes']['']['en']);
        $this->assertEquals(new \stdClass(), $result[0]['attributes']['']['en']);
    }

    public function testFormatProductWithNullChildren(): void
    {
        $product = $this->createSimpleProduct('prod-null-kids', 'No Kids', 'SKU-NK');

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['is_parent']);
    }

    public function testFormatProductWithEmptyChildren(): void
    {
        $product = $this->createSimpleProduct('prod-empty-kids', 'Empty Kids', 'SKU-EK', children: new ProductCollection());

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['is_parent']);
    }

    public function testFormatProductMultiChannelMultiLanguage(): void
    {
        $product = $this->createSimpleProduct('prod-multi', 'Multi Product', 'SKU-MULTI');

        $channelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'de', 'domainUrl' => 'https://shop.de', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-de'],
            ],
            'b2b' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://b2b.shop.com', 'currencyIso' => 'USD', 'currencyId' => 'curr-usd', 'salesChannelId' => 'sc-2', 'languageId' => 'lang-en'],
            ],
        ];

        $result = $this->formatter->formatProduct($product, $channelContexts);

        $this->assertCount(1, $result);
        $this->assertSame(['', 'b2b'], $result[0]['channels']);
        $this->assertArrayHasKey('', $result[0]['names']);
        $this->assertArrayHasKey('b2b', $result[0]['names']);
        $this->assertArrayHasKey('en', $result[0]['names']['']);
        $this->assertArrayHasKey('de', $result[0]['names']['']);
        $this->assertArrayHasKey('en', $result[0]['names']['b2b']);

        // Categories are per-channel per-language
        $this->assertArrayHasKey('en', $result[0]['categories']['']);
        $this->assertArrayHasKey('de', $result[0]['categories']['']);
        $this->assertArrayHasKey('en', $result[0]['categories']['b2b']);
        // Brands are per-channel
        $this->assertIsString($result[0]['brands']['']);
        $this->assertIsString($result[0]['brands']['b2b']);
    }

    /**
     * @return ProductEntity&MockObject
     */
    public function testFormatProductWithNestedCategoryHierarchy(): void
    {
        $category = $this->createMock(CategoryEntity::class);
        $category->method('getUniqueIdentifier')->willReturn('cat-nested');
        $category->method('getTranslations')->willReturn(null);
        $category->method('getTranslation')->willReturnCallback(function (string $field) {
            return match ($field) {
                'breadcrumb' => ['root-id' => 'Catalogue #1', 'food-id' => 'Food', 'bakery-id' => 'Bakery products'],
                'name' => 'Bakery products',
                default => null,
            };
        });
        $category->method('getBreadcrumb')->willReturn(['root-id' => 'Catalogue #1', 'food-id' => 'Food', 'bakery-id' => 'Bakery products']);
        $category->method('getName')->willReturn('Bakery products');

        $categories = new CategoryCollection([$category]);

        $product = $this->createSimpleProduct('prod-nested', 'Nested Cat Product', 'SKU-NC');
        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('prod-nested');
        $product->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'Nested Cat Product', 'description' => '', default => null });
        $product->method('getName')->willReturn('Nested Cat Product');
        $product->method('getProductNumber')->willReturn('SKU-NC');
        $product->method('getChildren')->willReturn(null);
        $product->method('getSeoUrls')->willReturn(null);
        $product->method('getCategories')->willReturn($categories);
        $product->method('getManufacturer')->willReturn(null);
        $product->method('getMedia')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getProperties')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);
        $product->method('getAvailable')->willReturn(true);
        $product->method('getAvailableStock')->willReturn(5);
        $product->method('getStock')->willReturn(5);
        $product->method('getVisibilities')->willReturn(null);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        // Root "Catalogue #1" should be stripped, leaving "Food > Bakery products"
        $this->assertSame(['Food > Bakery products'], $result[0]['categories']['']['en']);
    }

    public function testFormatProductWithMultipleCategories(): void
    {
        $cat1 = $this->createMock(CategoryEntity::class);
        $cat1->method('getUniqueIdentifier')->willReturn('cat-smartphones');
        $cat1->method('getTranslations')->willReturn(null);
        $cat1->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) {
            'breadcrumb' => ['root' => 'Catalogue #1', 'smart' => 'Smartphones'],
            'name' => 'Smartphones',
            default => null,
        });
        $cat1->method('getBreadcrumb')->willReturn(['root' => 'Catalogue #1', 'smart' => 'Smartphones']);

        $cat2 = $this->createMock(CategoryEntity::class);
        $cat2->method('getUniqueIdentifier')->willReturn('cat-electronics');
        $cat2->method('getTranslations')->willReturn(null);
        $cat2->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) {
            'breadcrumb' => ['root' => 'Catalogue #1', 'elec' => 'Electronics', 'mobile' => 'Mobile Devices'],
            'name' => 'Mobile Devices',
            default => null,
        });
        $cat2->method('getBreadcrumb')->willReturn(['root' => 'Catalogue #1', 'elec' => 'Electronics', 'mobile' => 'Mobile Devices']);

        $categories = new CategoryCollection([$cat1, $cat2]);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('prod-multi-cat');
        $product->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'Multi Cat', 'description' => '', default => null });
        $product->method('getName')->willReturn('Multi Cat');
        $product->method('getProductNumber')->willReturn('SKU-MC');
        $product->method('getChildren')->willReturn(null);
        $product->method('getSeoUrls')->willReturn(null);
        $product->method('getCategories')->willReturn($categories);
        $product->method('getManufacturer')->willReturn(null);
        $product->method('getMedia')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getProperties')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);
        $product->method('getAvailable')->willReturn(true);
        $product->method('getAvailableStock')->willReturn(5);
        $product->method('getStock')->willReturn(5);
        $product->method('getVisibilities')->willReturn(null);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $cats = $result[0]['categories']['']['en'];
        $this->assertCount(2, $cats);
        $this->assertContains('Smartphones', $cats);
        $this->assertContains('Electronics > Mobile Devices', $cats);
    }

    public function testFormatProductDedupesDuplicateCategoryPaths(): void
    {
        // Two distinct category entities resolving to the identical breadcrumb path.
        $cat1 = $this->createMock(CategoryEntity::class);
        $cat1->method('getUniqueIdentifier')->willReturn('cat-dup-1');
        $cat1->method('getTranslations')->willReturn(null);
        $cat1->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) {
            'breadcrumb' => ['root' => 'Catalogue', 'shoes' => 'Shoes'],
            'name' => 'Shoes',
            default => null,
        });
        $cat1->method('getBreadcrumb')->willReturn(['root' => 'Catalogue', 'shoes' => 'Shoes']);

        $cat2 = $this->createMock(CategoryEntity::class);
        $cat2->method('getUniqueIdentifier')->willReturn('cat-dup-2');
        $cat2->method('getTranslations')->willReturn(null);
        $cat2->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) {
            'breadcrumb' => ['root' => 'Catalogue', 'shoes' => 'Shoes'],
            'name' => 'Shoes',
            default => null,
        });
        $cat2->method('getBreadcrumb')->willReturn(['root' => 'Catalogue', 'shoes' => 'Shoes']);

        $categories = new CategoryCollection([$cat1, $cat2]);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('prod-dup-cat');
        $product->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'Dup Cat Product', 'description' => '', default => null });
        $product->method('getName')->willReturn('Dup Cat Product');
        $product->method('getProductNumber')->willReturn('SKU-DUP');
        $product->method('getChildren')->willReturn(null);
        $product->method('getSeoUrls')->willReturn(null);
        $product->method('getCategories')->willReturn($categories);
        $product->method('getManufacturer')->willReturn(null);
        $product->method('getMedia')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getProperties')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);
        $product->method('getAvailable')->willReturn(true);
        $product->method('getAvailableStock')->willReturn(5);
        $product->method('getStock')->willReturn(5);
        $product->method('getVisibilities')->willReturn(null);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['Shoes'], $result[0]['categories']['']['en']);
    }

    public function testFormatProductCategoryFallbackToName(): void
    {
        // Category with no breadcrumb — should fall back to name
        $category = $this->createMock(CategoryEntity::class);
        $category->method('getUniqueIdentifier')->willReturn('cat-nobc');
        $category->method('getTranslations')->willReturn(null);
        $category->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) {
            'breadcrumb' => [],
            'name' => 'Orphan Category',
            default => null,
        });
        $category->method('getBreadcrumb')->willReturn([]);
        $category->method('getName')->willReturn('Orphan Category');

        $categories = new CategoryCollection([$category]);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('prod-nobc');
        $product->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'No BC Product', 'description' => '', default => null });
        $product->method('getName')->willReturn('No BC Product');
        $product->method('getProductNumber')->willReturn('SKU-NOBC');
        $product->method('getChildren')->willReturn(null);
        $product->method('getSeoUrls')->willReturn(null);
        $product->method('getCategories')->willReturn($categories);
        $product->method('getManufacturer')->willReturn(null);
        $product->method('getMedia')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getProperties')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);
        $product->method('getAvailable')->willReturn(true);
        $product->method('getAvailableStock')->willReturn(5);
        $product->method('getStock')->willReturn(5);
        $product->method('getVisibilities')->willReturn(null);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['Orphan Category'], $result[0]['categories']['']['en']);
    }

    public function testFormatProductSingleEntryBreadcrumbKeepsEntry(): void
    {
        // Category with only root in breadcrumb (level 1 category)
        $category = $this->createMock(CategoryEntity::class);
        $category->method('getUniqueIdentifier')->willReturn('cat-root-only');
        $category->method('getTranslations')->willReturn(null);
        $category->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) {
            'breadcrumb' => ['root' => 'Top Level'],
            'name' => 'Top Level',
            default => null,
        });
        $category->method('getBreadcrumb')->willReturn(['root' => 'Top Level']);

        $categories = new CategoryCollection([$category]);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('prod-rootonly');
        $product->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'Root Only', 'description' => '', default => null });
        $product->method('getName')->willReturn('Root Only');
        $product->method('getProductNumber')->willReturn('SKU-RO');
        $product->method('getChildren')->willReturn(null);
        $product->method('getSeoUrls')->willReturn(null);
        $product->method('getCategories')->willReturn($categories);
        $product->method('getManufacturer')->willReturn(null);
        $product->method('getMedia')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getProperties')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);
        $product->method('getAvailable')->willReturn(true);
        $product->method('getAvailableStock')->willReturn(5);
        $product->method('getStock')->willReturn(5);
        $product->method('getVisibilities')->willReturn(null);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        // Single-entry breadcrumb should NOT be stripped (it's the only entry)
        $this->assertSame(['Top Level'], $result[0]['categories']['']['en']);
    }

    public function testFormatProductVisibilityFiltersChannels(): void
    {
        $visibility = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityEntity::class);
        $visibility->method('getUniqueIdentifier')->willReturn('vis-1');
        $visibility->method('getSalesChannelId')->willReturn('sc-1');

        $visibilities = new \Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityCollection([$visibility]);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('prod-vis');
        $product->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'Visible', 'description' => '', default => null });
        $product->method('getName')->willReturn('Visible');
        $product->method('getProductNumber')->willReturn('SKU-VIS');
        $product->method('getChildren')->willReturn(null);
        $product->method('getSeoUrls')->willReturn(null);
        $product->method('getCategories')->willReturn(null);
        $product->method('getManufacturer')->willReturn(null);
        $product->method('getMedia')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getProperties')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);
        $product->method('getAvailable')->willReturn(true);
        $product->method('getAvailableStock')->willReturn(5);
        $product->method('getStock')->willReturn(5);
        $product->method('getVisibilities')->willReturn($visibilities);

        // Channel mapped to sc-1 should be included, sc-2 should be filtered out
        $multiChannelContexts = [
            'retail' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'c1', 'salesChannelId' => 'sc-1', 'languageId' => 'l1'],
            ],
            'b2b' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://b2b.com', 'currencyIso' => 'EUR', 'currencyId' => 'c1', 'salesChannelId' => 'sc-2', 'languageId' => 'l1'],
            ],
        ];

        $result = $this->formatter->formatProduct($product, $multiChannelContexts);

        $this->assertCount(1, $result);
        $this->assertSame(['retail'], $result[0]['channels']);
    }

    public function testFormatProductLinksToAVisibleSalesChannelWhenChannelKeyHasSeveral(): void
    {
        $visibility = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityEntity::class);
        $visibility->method('getUniqueIdentifier')->willReturn('vis-2');
        $visibility->method('getSalesChannelId')->willReturn('sc-2');
        $visibilities = new \Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityCollection([$visibility]);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('prod-vis-2');
        $product->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'Visible in sc-2', 'description' => '', default => null });
        $product->method('getName')->willReturn('Visible in sc-2');
        $product->method('getProductNumber')->willReturn('SKU-VIS-2');
        $product->method('getChildren')->willReturn(null);
        $product->method('getSeoUrls')->willReturn(null);
        $product->method('getCategories')->willReturn(null);
        $product->method('getManufacturer')->willReturn(null);
        $product->method('getMedia')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getProperties')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);
        $product->method('getAvailable')->willReturn(true);
        $product->method('getAvailableStock')->willReturn(5);
        $product->method('getStock')->willReturn(5);
        $product->method('getVisibilities')->willReturn($visibilities);

        // Two sales channels share the Emporiqa channel key; the product is only visible in the second
        $contexts = [
            'retail' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'c1', 'salesChannelId' => 'sc-1', 'languageId' => 'l1'],
                ['languageCode' => 'en', 'domainUrl' => 'https://b2b.com', 'currencyIso' => 'EUR', 'currencyId' => 'c1', 'salesChannelId' => 'sc-2', 'languageId' => 'l1'],
            ],
        ];

        $result = $this->formatter->formatProduct($product, $contexts);

        $this->assertCount(1, $result);
        $this->assertStringStartsWith('https://b2b.com', $result[0]['links']['retail']['en']);
    }

    public function testFormatProductVisibilityReturnsEmptyWhenNotVisible(): void
    {
        $visibility = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityEntity::class);
        $visibility->method('getUniqueIdentifier')->willReturn('vis-1');
        $visibility->method('getSalesChannelId')->willReturn('sc-other');

        $visibilities = new \Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityCollection([$visibility]);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('prod-invis');
        $product->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'Invisible', default => null });
        $product->method('getName')->willReturn('Invisible');
        $product->method('getProductNumber')->willReturn('SKU-INV');
        $product->method('getChildren')->willReturn(null);
        $product->method('getVisibilities')->willReturn($visibilities);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        // Product not visible in sc-1 → empty result
        $this->assertCount(0, $result);
    }

    public function testCategoriesMultiLanguage(): void
    {
        $cat = $this->createMock(CategoryEntity::class);
        $cat->method('getUniqueIdentifier')->willReturn('cat-ml');
        $cat->method('getTranslations')->willReturn(null);
        $cat->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) {
            'breadcrumb' => ['root' => 'Catalogue', 'shoes' => 'Shoes'],
            'name' => 'Shoes',
            default => null,
        });
        $cat->method('getBreadcrumb')->willReturn(['root' => 'Catalogue', 'shoes' => 'Shoes']);
        $cat->method('getName')->willReturn('Shoes');

        $categories = new CategoryCollection([$cat]);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('prod-cat-ml');
        $product->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'Shoe Product', 'description' => '', default => null });
        $product->method('getName')->willReturn('Shoe Product');
        $product->method('getProductNumber')->willReturn('SKU-SHOE');
        $product->method('getChildren')->willReturn(null);
        $product->method('getSeoUrls')->willReturn(null);
        $product->method('getCategories')->willReturn($categories);
        $product->method('getManufacturer')->willReturn(null);
        $product->method('getMedia')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getProperties')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);
        $product->method('getAvailable')->willReturn(true);
        $product->method('getAvailableStock')->willReturn(5);
        $product->method('getStock')->willReturn(5);
        $product->method('getVisibilities')->willReturn(null);

        $channelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'c1', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'de', 'domainUrl' => 'https://shop.de', 'currencyIso' => 'EUR', 'currencyId' => 'c1', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-de'],
            ],
        ];

        $result = $this->formatter->formatProduct($product, $channelContexts);

        $this->assertArrayHasKey('en', $result[0]['categories']['']);
        $this->assertArrayHasKey('de', $result[0]['categories']['']);
        $this->assertSame(['Shoes'], $result[0]['categories']['']['en']);
        $this->assertSame(['Shoes'], $result[0]['categories']['']['de']);
    }

    public function testVariationAttributesTranslatedPerLanguage(): void
    {
        $colorGroup = $this->createMock(\Shopware\Core\Content\Property\PropertyGroupEntity::class);
        $colorGroup->method('getUniqueIdentifier')->willReturn('group-color');
        $colorGroup->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'Color', default => null });
        $colorGroup->method('getName')->willReturn('Color');
        $colorGroup->method('getTranslations')->willReturn(null);

        $option = $this->createMock(\Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity::class);
        $option->method('getUniqueIdentifier')->willReturn('opt-red');
        $option->method('getGroup')->willReturn($colorGroup);

        $options = new \Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection([$option]);

        $variant = $this->createMock(ProductEntity::class);
        $variant->method('getId')->willReturn('var-va');
        $variant->method('getUniqueIdentifier')->willReturn('var-va');
        $variant->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) { 'name' => 'Variant VA', 'description' => null, default => null });
        $variant->method('getName')->willReturn('Variant VA');
        $variant->method('getProductNumber')->willReturn('VAR-VA');
        $variant->method('getSeoUrls')->willReturn(null);
        $variant->method('getMedia')->willReturn(null);
        $variant->method('getCover')->willReturn(null);
        $variant->method('getPrice')->willReturn(null);
        $variant->method('getOptions')->willReturn($options);
        $variant->method('getTranslations')->willReturn(null);
        $variant->method('getAvailable')->willReturn(true);
        $variant->method('getAvailableStock')->willReturn(3);
        $variant->method('getStock')->willReturn(3);

        $children = new ProductCollection([$variant]);
        $product = $this->createSimpleProduct('prod-va', 'VA Product', 'SKU-VA', children: $children);

        $channelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'c1', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'de', 'domainUrl' => 'https://shop.de', 'currencyIso' => 'EUR', 'currencyId' => 'c1', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-de'],
            ],
        ];

        $result = $this->formatter->formatProduct($product, $channelContexts);

        $varAttrs = $result[0]['variation_attributes'];
        $this->assertArrayHasKey('', $varAttrs);
        $this->assertArrayHasKey('en', $varAttrs['']);
        $this->assertArrayHasKey('de', $varAttrs['']);
        $this->assertSame(['Color'], $varAttrs['']['en']);
        $this->assertSame(['Color'], $varAttrs['']['de']);
    }

    public function testVariationAttributesEmptyForNonParent(): void
    {
        $product = $this->createSimpleProduct('prod-simple-va', 'Simple', 'SKU-SVA');

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertInstanceOf(\stdClass::class, $result[0]['variation_attributes']);
    }

    public function testFormatProductNewContractFieldsDefaults(): void
    {
        $product = $this->createSimpleProduct('prod-contract', 'Contract', 'SKU-CT');

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $parent = $result[0];
        $this->assertSame(['' => 1], $parent['min_order_quantities']);
        $this->assertSame(['' => null], $parent['max_order_quantities']);
        $this->assertTrue($parent['available_for_order']);
        $this->assertNull($parent['condition']);
        $this->assertFalse($parent['is_virtual']);
    }

    public function testFormatProductMinMaxOrderQuantities(): void
    {
        $product = $this->createSimpleProduct('prod-minmax', 'MinMax', 'SKU-MM', minPurchase: 3, maxPurchase: 10);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(['' => 3], $result[0]['min_order_quantities']);
        $this->assertSame(['' => 10], $result[0]['max_order_quantities']);
    }

    public function testFormatProductIsVirtualForDownloadState(): void
    {
        $product = $this->createSimpleProduct('prod-dl', 'Download', 'SKU-DL', states: ['is-download']);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertTrue($result[0]['is_virtual']);
    }

    public function testFormatProductIsVirtualFalseForPhysicalState(): void
    {
        $product = $this->createSimpleProduct('prod-phys', 'Physical', 'SKU-PH', states: ['is-physical']);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertFalse($result[0]['is_virtual']);
    }

    public function testVariantInheritsMinMaxOrderQuantitiesFromParent(): void
    {
        $inheriting = $this->createVariantProduct('var-mm-1', 'MM 1', 'MM-1');
        $own = $this->createVariantProduct('var-mm-2', 'MM 2', 'MM-2', minPurchase: 2, maxPurchase: 5);

        $children = new ProductCollection([$inheriting, $own]);
        $product = $this->createSimpleProduct('parent-mm', 'MM Parent', 'MM-P', children: $children, minPurchase: 4, maxPurchase: 20);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        // Variant with null min/max inherits parent values
        $this->assertSame(['' => 4], $result[1]['min_order_quantities']);
        $this->assertSame(['' => 20], $result[1]['max_order_quantities']);
        // Variant with own values keeps them
        $this->assertSame(['' => 2], $result[2]['min_order_quantities']);
        $this->assertSame(['' => 5], $result[2]['max_order_quantities']);
        // Contract-parity fields also present on variations
        $this->assertTrue($result[1]['available_for_order']);
        $this->assertNull($result[1]['condition']);
        $this->assertFalse($result[1]['is_virtual']);
    }

    public function testTierPricesSortedDedupedAndNoOpDropped(): void
    {
        $tierRows = new ProductPriceCollection([
            // qty 1 → ignored (base price)
            $this->createTierRow('tier-0', 1, 39.99, 33.60),
            // qty 10 → kept
            $this->createTierRow('tier-1', 10, 30.00, 25.21),
            // qty 5, two overlapping rules → dedupe keeps lowest (35.99)
            $this->createTierRow('tier-2', 5, 36.50, 30.67),
            $this->createTierRow('tier-3', 5, 35.99, 30.24),
            // qty 20 not cheaper than current price → dropped as no-op
            $this->createTierRow('tier-4', 20, 45.00, 37.82),
            // qty 15 has no price for this currency → skipped
            $this->createTierRow('tier-5', 15, 28.00, 23.53, currencyId: 'curr-other'),
        ]);

        $product = $this->createSimpleProduct('prod-tiers', 'Tiers', 'SKU-TP', grossPrice: 39.99, netPrice: 33.60, tierPrices: $tierRows);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $priceEntry = $result[0]['prices'][''][0];
        $this->assertSame(39.99, $priceEntry['current_price']);
        $this->assertSame(
            [
                ['min_quantity' => 5, 'price' => 35.99],
                ['min_quantity' => 10, 'price' => 30.00],
            ],
            $priceEntry['tier_prices'],
        );
    }

    public function testPriceEntrySendsGrossHeadlineWithInclExclWhenTaxApplies(): void
    {
        // Tax present (gross != net): headline is gross, and the incl/excl
        // pair is always included so the store's "Show tax info" dashboard
        // toggle can render the breakdown. Tiers use gross too.
        $tierRows = new ProductPriceCollection([
            $this->createTierRow('tier-tax', 5, 35.99, 30.24),
        ]);

        $product = $this->createSimpleProduct('prod-tax', 'Taxed', 'SKU-TAX', grossPrice: 39.99, netPrice: 33.60, tierPrices: $tierRows);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $priceEntry = $result[0]['prices'][''][0];
        $this->assertSame(39.99, $priceEntry['current_price']);
        $this->assertSame(39.99, $priceEntry['price_incl_tax']);
        $this->assertSame(33.60, $priceEntry['price_excl_tax']);
        $this->assertSame([['min_quantity' => 5, 'price' => 35.99]], $priceEntry['tier_prices']);
    }

    public function testPriceEntryOmitsInclExclWhenNoTax(): void
    {
        // gross == net (no tax): the incl/excl pair is not sent.
        $product = $this->createSimpleProduct('prod-notax', 'Untaxed', 'SKU-NOTAX', grossPrice: 20.00);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $priceEntry = $result[0]['prices'][''][0];
        $this->assertSame(20.00, $priceEntry['current_price']);
        $this->assertArrayNotHasKey('price_incl_tax', $priceEntry);
        $this->assertArrayNotHasKey('price_excl_tax', $priceEntry);
    }

    public function testNoTierPricesKeyWhenPricesNotLoaded(): void
    {
        $product = $this->createSimpleProduct('prod-notiers', 'No Tiers', 'SKU-NT', grossPrice: 39.99);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $priceEntry = $result[0]['prices'][''][0];
        $this->assertArrayNotHasKey('tier_prices', $priceEntry);
    }

    public function testFormatAvailabilityEventsForSimpleProduct(): void
    {
        $product = $this->createSimpleProduct('prod-ev', 'Event Product', 'SKU-EV', availableStock: 8);

        $events = $this->formatter->formatAvailabilityEvents($product, $this->defaultChannelContexts);

        $this->assertCount(1, $events);
        $this->assertSame(
            [
                'identification_number' => 'product-prod-ev',
                'sku' => 'SKU-EV',
                'availability_statuses' => ['' => 'available'],
                'stock_quantities' => ['' => 8],
            ],
            $events[0],
        );
    }

    public function testFormatAvailabilityEventsForVariantProduct(): void
    {
        $available = $this->createVariantProduct('var-ev-1', 'Ev 1', 'EV-1', stock: 4);
        $backorder = $this->createVariantProduct('var-ev-2', 'Ev 2', 'EV-2', stock: 0);
        $closeout = $this->createVariantProduct('var-ev-3', 'Ev 3', 'EV-3', stock: 0, isCloseout: true);
        $inactive = $this->createVariantProduct('var-ev-4', 'Ev 4', 'EV-4', stock: 9, active: false);

        $children = new ProductCollection([$available, $backorder, $closeout, $inactive]);
        $product = $this->createSimpleProduct('parent-ev', 'Ev Parent', 'EV-P', children: $children);

        $events = $this->formatter->formatAvailabilityEvents($product, $this->defaultChannelContexts);

        // One entry per active child, no parent entry
        $this->assertCount(3, $events);
        $ids = array_column($events, 'identification_number');
        $this->assertSame(['variation-var-ev-1', 'variation-var-ev-2', 'variation-var-ev-3'], $ids);

        $this->assertSame(['' => 'available'], $events[0]['availability_statuses']);
        $this->assertSame(['' => 4], $events[0]['stock_quantities']);
        $this->assertSame(['' => 'backorder'], $events[1]['availability_statuses']);
        $this->assertSame(['' => 'out_of_stock'], $events[2]['availability_statuses']);

        foreach ($events as $event) {
            $this->assertSame(
                ['identification_number', 'sku', 'availability_statuses', 'stock_quantities'],
                array_keys($event),
            );
        }
    }

    public function testFormatAvailabilityEventsRespectsVisibilityFilter(): void
    {
        $visibility = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityEntity::class);
        $visibility->method('getUniqueIdentifier')->willReturn('vis-ev');
        $visibility->method('getSalesChannelId')->willReturn('sc-other');

        $visibilities = new \Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityCollection([$visibility]);

        $product = $this->createSimpleProduct('prod-ev-invis', 'Invisible Ev', 'SKU-EVI', visibilities: $visibilities);

        $events = $this->formatter->formatAvailabilityEvents($product, $this->defaultChannelContexts);

        $this->assertSame([], $events);
    }

    public function testFormatAvailabilityEventsForDirectVariantEntity(): void
    {
        // A variant passed straight through (stock-only writes on a child
        // bypass the parent) must use the variation- prefix, not product-.
        $variant = $this->createVariantProduct('var-direct', 'Direct Variant', 'VAR-DIRECT', stock: 6);
        $variant->method('getParentId')->willReturn('parent-direct');

        $events = $this->formatter->formatAvailabilityEvents($variant, $this->defaultChannelContexts);

        $this->assertCount(1, $events);
        $this->assertSame('variation-var-direct', $events[0]['identification_number']);
        $this->assertSame('VAR-DIRECT', $events[0]['sku']);
        $this->assertSame(['' => 'available'], $events[0]['availability_statuses']);
        $this->assertSame(['' => 6], $events[0]['stock_quantities']);
    }

    public function testFormatAvailabilityEventsForVariantWithNullVisibilityKeepsAllChannels(): void
    {
        // Null visibilities on a variant (inherited from the parent) must
        // not filter every channel context away.
        $variant = $this->createVariantProduct('var-vis-null', 'Vis Null Variant', 'VAR-VIS-NULL', stock: 2);
        $variant->method('getParentId')->willReturn('parent-vis-null');
        $variant->method('getVisibilities')->willReturn(null);

        $multiChannelContexts = [
            'retail' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'c1', 'salesChannelId' => 'sc-1', 'languageId' => 'l1'],
            ],
            'b2b' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://b2b.com', 'currencyIso' => 'EUR', 'currencyId' => 'c1', 'salesChannelId' => 'sc-2', 'languageId' => 'l1'],
            ],
        ];

        $events = $this->formatter->formatAvailabilityEvents($variant, $multiChannelContexts);

        $this->assertCount(1, $events);
        $this->assertSame('variation-var-vis-null', $events[0]['identification_number']);
        $this->assertSame(['retail', 'b2b'], array_keys($events[0]['availability_statuses']));
    }

    private function createTierRow(string $id, int $quantityStart, float $gross, float $net, string $currencyId = 'curr-eur'): ProductPriceEntity
    {
        $row = new ProductPriceEntity();
        $row->setId(str_pad($id, 32, '0'));
        $row->setUniqueIdentifier($id);
        $row->setQuantityStart($quantityStart);
        $row->setPrice(new PriceCollection([new Price($currencyId, $net, $gross, false)]));

        return $row;
    }

    private function createSimpleProduct(
        string $id,
        string $name,
        string $productNumber,
        bool $available = true,
        int $availableStock = 5,
        ?ProductCollection $children = null,
        bool $isCloseout = false,
        ?int $minPurchase = null,
        ?int $maxPurchase = null,
        ?array $states = null,
        ?float $grossPrice = null,
        ?float $netPrice = null,
        ?ProductPriceCollection $tierPrices = null,
        ?\Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityCollection $visibilities = null,
    ): ProductEntity&MockObject {
        $price = $grossPrice !== null
            ? new PriceCollection([new Price('curr-eur', $netPrice ?? $grossPrice, $grossPrice, false)])
            : null;

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn($id);
        $product->method('getTranslation')->willReturnCallback(function (string $field) use ($name) {
            return match ($field) {
                'name' => $name,
                'description' => 'Test description',
                default => null,
            };
        });
        $product->method('getName')->willReturn($name);
        $product->method('getDescription')->willReturn('Test description');
        $product->method('getProductNumber')->willReturn($productNumber);
        $product->method('getChildren')->willReturn($children);
        $product->method('getSeoUrls')->willReturn(null);
        $product->method('getCategories')->willReturn(null);
        $product->method('getManufacturer')->willReturn(null);
        $product->method('getMedia')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getCurrencyPrice')->willReturn(null);
        $product->method('getPrice')->willReturn($price);
        $product->method('getPrices')->willReturn($tierPrices);
        $product->method('getProperties')->willReturn(null);
        $product->method('getOptions')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);
        $product->method('getAvailable')->willReturn($available);
        $product->method('getAvailableStock')->willReturn($availableStock);
        $product->method('getStock')->willReturn($availableStock);
        $product->method('getVisibilities')->willReturn($visibilities);
        $product->method('getIsCloseout')->willReturn($isCloseout);
        $product->method('getMinPurchase')->willReturn($minPurchase);
        $product->method('getMaxPurchase')->willReturn($maxPurchase);
        $product->method('get')->willReturnCallback(fn (string $property) => match ($property) {
            'states' => $states,
            'isCloseout' => $isCloseout ?: null,
            default => null,
        });

        return $product;
    }

    /**
     * @return ProductEntity&MockObject
     */
    private function createVariantProduct(
        string $id,
        string $name,
        string $productNumber,
        int $stock = 3,
        ?bool $isCloseout = null,
        ?bool $active = null,
        ?int $minPurchase = null,
        ?int $maxPurchase = null,
    ): ProductEntity&MockObject {
        $variant = $this->createMock(ProductEntity::class);
        $variant->method('getId')->willReturn($id);
        $variant->method('getUniqueIdentifier')->willReturn($id);
        $variant->method('getTranslation')->willReturnCallback(function (string $field) use ($name) {
            return match ($field) {
                'name' => $name,
                'description' => null,
                default => null,
            };
        });
        $variant->method('getName')->willReturn($name);
        $variant->method('getProductNumber')->willReturn($productNumber);
        $variant->method('getSeoUrls')->willReturn(null);
        $variant->method('getMedia')->willReturn(null);
        $variant->method('getCover')->willReturn(null);
        $variant->method('getCurrencyPrice')->willReturn(null);
        $variant->method('getPrice')->willReturn(null);
        $variant->method('getPrices')->willReturn(null);
        $variant->method('getOptions')->willReturn(null);
        $variant->method('getTranslations')->willReturn(null);
        $variant->method('getAvailable')->willReturn($stock > 0);
        $variant->method('getAvailableStock')->willReturn($stock);
        $variant->method('getStock')->willReturn($stock);
        $variant->method('getActive')->willReturn($active);
        $variant->method('getIsCloseout')->willReturn($isCloseout ?? false);
        $variant->method('getMinPurchase')->willReturn($minPurchase);
        $variant->method('getMaxPurchase')->willReturn($maxPurchase);
        $variant->method('get')->willReturnCallback(fn (string $property) => match ($property) {
            'isCloseout' => $isCloseout,
            default => null,
        });

        return $variant;
    }
}
