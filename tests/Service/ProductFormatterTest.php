<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\GuestRuleResolverInterface;
use Emporiqa\ShopwarePlugin\Service\ProductFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceCollection;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;

class ProductFormatterTest extends TestCase
{
    private const PUBLIC_RULE = 'rule-public';
    private const DEALER_RULE = 'rule-dealer';

    private ConfigServiceInterface&MockObject $config;
    private GuestRuleResolverInterface&MockObject $guestRuleResolver;
    private ProductFormatter $formatter;

    /** @var array<string, array<int, array<string, string>>> */
    private array $defaultChannelContexts;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->config->method('getBrandAttribute')->willReturn('');

        // Guests match only the public rule unless a test says otherwise
        $this->guestRuleResolver = $this->createMock(GuestRuleResolverInterface::class);
        $this->guestRuleResolver->method('getGuestRuleIds')->willReturn([self::PUBLIC_RULE]);

        $this->formatter = new ProductFormatter($this->config, $this->guestRuleResolver);

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
        $this->assertSame('PARENT-SKU', $var1['parent_sku']);

        $var2 = $result[2];
        $this->assertSame('variation-var-002', $var2['identification_number']);
        $this->assertSame('PARENT-SKU', $var2['parent_sku']);
    }

    /**
     * Emporiqa stores a variation as a lean row that inherits descriptions,
     * categories and brands from its parent and fixes variation_attributes
     * and is_parent, so a variation payload never carries them. Parents and
     * simple products keep every field.
     */
    public function testVariationPayloadOmitsFieldsTheParentOwns(): void
    {
        $children = new ProductCollection([$this->createVariantProduct('var-001', 'Variant 1', 'VAR-SKU-001')]);
        $product = $this->createSimpleProduct('parent-001', 'Parent Product', 'PARENT-SKU', children: $children);

        [$parent, $variation] = $this->formatter->formatProduct($product, $this->defaultChannelContexts, 'session-1');

        foreach (['descriptions', 'categories', 'brands', 'variation_attributes', 'is_parent'] as $key) {
            $this->assertArrayNotHasKey($key, $variation, $key);
            $this->assertArrayHasKey($key, $parent, $key);
        }
        foreach (['identification_number', 'sku', 'parent_sku', 'channels', 'names', 'links', 'attributes', 'prices', 'availability_statuses', 'stock_quantities', 'images', 'min_order_quantities', 'max_order_quantities', 'available_for_order', 'condition', 'is_virtual', 'sync_session_id'] as $key) {
            $this->assertArrayHasKey($key, $variation, $key);
        }

        $simple = $this->formatter->formatProduct($this->createSimpleProduct('prod-001', 'Simple', 'SKU-001'), $this->defaultChannelContexts)[0];
        foreach (['descriptions', 'categories', 'brands', 'variation_attributes', 'is_parent'] as $key) {
            $this->assertArrayHasKey($key, $simple, $key);
        }
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
        $visibility->method('getVisibility')->willReturn(30);

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
        $visibility->method('getVisibility')->willReturn(30);
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

    /**
     * A product assigned to no sales channel is shown by no storefront, so it
     * is never synced (an empty, loaded visibility list is not "unknown").
     */
    public function testProductInNoSalesChannelIsNotSynced(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('prod-nowhere');
        $product->method('getProductNumber')->willReturn('SKU-NOWHERE');
        $product->method('getChildren')->willReturn(null);
        $product->method('getVisibilities')->willReturn(new \Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityCollection());

        $this->assertSame([], $this->formatter->formatProduct($product, $this->defaultChannelContexts));
        $this->assertSame([], $this->formatter->formatAvailabilityEvents($product, $this->defaultChannelContexts));
    }

    /**
     * "Hide in listings and search" hides a product from the storefront's search and listings,
     * so the chat (a search) must not offer it either; "Hide in listings"
     * still lets search find it, so it stays.
     */
    public function testLinkOnlyProductIsNotSyncedButSearchVisibleIs(): void
    {
        $linkOnly = $this->createSimpleProduct('prod-link', 'Link only', 'SKU-LINK', visibilities: $this->visibilities(['sc-1' => 10]));
        $this->assertSame([], $this->formatter->formatProduct($linkOnly, $this->defaultChannelContexts));
        $this->assertSame([], $this->formatter->formatAvailabilityEvents($linkOnly, $this->defaultChannelContexts));

        $searchOnly = $this->createSimpleProduct('prod-search', 'Hidden in listings', 'SKU-SEARCH', visibilities: $this->visibilities(['sc-1' => 20]));
        $result = $this->formatter->formatProduct($searchOnly, $this->defaultChannelContexts);
        $this->assertCount(1, $result);
        $this->assertSame([''], $result[0]['channels']);
    }

    public function testProductIsSyncedOnlyToTheChannelsWhereSearchFindsIt(): void
    {
        $product = $this->createSimpleProduct('prod-mixed', 'Mixed', 'SKU-MIXED', visibilities: $this->visibilities(['sc-1' => 30, 'sc-2' => 10]));
        $contexts = [
            'retail' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'l1'],
            ],
            'b2b' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://b2b.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-2', 'languageId' => 'l1'],
            ],
        ];

        $result = $this->formatter->formatProduct($product, $contexts);

        $this->assertCount(1, $result);
        $this->assertSame(['retail'], $result[0]['channels']);
        $this->assertSame(['retail'], array_keys($result[0]['links']));
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
            // qty 5 repeated within the rule → dedupe keeps lowest (35.99)
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

    /**
     * A tier from a rule scoped to a customer group ("Dealer") must never be
     * published, however cheap it is. Merging every rule's prices used to put
     * B2B prices in front of every guest shopper.
     */
    public function testTierPricesFromRulesGuestsDoNotMatchAreNeverPublished(): void
    {
        $tierRows = new ProductPriceCollection([
            $this->createTierRow('public-1', 1, 39.99, 33.60),
            $this->createTierRow('public-5', 5, 35.99, 30.24),
            $this->createTierRow('dealer-1', 1, 25.00, 21.01, ruleId: self::DEALER_RULE),
            $this->createTierRow('dealer-5', 5, 20.00, 16.81, ruleId: self::DEALER_RULE),
            $this->createTierRow('dealer-50', 50, 15.00, 12.61, ruleId: self::DEALER_RULE),
        ]);

        $product = $this->createSimpleProduct('prod-b2b', 'B2B', 'SKU-B2B', grossPrice: 39.99, netPrice: 33.60, tierPrices: $tierRows);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(39.99, $result[0]['prices'][''][0]['current_price']);
        $this->assertSame([['min_quantity' => 5, 'price' => 35.99]], $result[0]['prices'][''][0]['tier_prices']);
    }

    public function testOnlyRestrictedRulePricesMeansNoTierPrices(): void
    {
        $tierRows = new ProductPriceCollection([
            $this->createTierRow('dealer-1', 1, 25.00, 21.01, ruleId: self::DEALER_RULE),
            $this->createTierRow('dealer-10', 10, 20.00, 16.81, ruleId: self::DEALER_RULE),
        ]);

        $product = $this->createSimpleProduct('prod-dealer', 'Dealer only', 'SKU-D', grossPrice: 39.99, netPrice: 33.60, tierPrices: $tierRows);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(39.99, $result[0]['prices'][''][0]['current_price']);
        $this->assertArrayNotHasKey('tier_prices', $result[0]['prices'][''][0]);
    }

    /**
     * Like the storefront, the highest-priority matching rule that has prices
     * on the product wins outright; a lower-priority rule is not mixed in.
     */
    public function testHighestPriorityGuestRuleWithPricesWins(): void
    {
        $resolver = $this->createMock(GuestRuleResolverInterface::class);
        $resolver->method('getGuestRuleIds')->willReturn(['rule-no-prices', 'rule-sale', self::PUBLIC_RULE]);
        $formatter = new ProductFormatter($this->config, $resolver);

        $tierRows = new ProductPriceCollection([
            $this->createTierRow('sale-1', 1, 38.00, 31.93, ruleId: 'rule-sale'),
            $this->createTierRow('sale-10', 10, 32.00, 26.89, ruleId: 'rule-sale'),
            $this->createTierRow('public-1', 1, 39.99, 33.60),
            $this->createTierRow('public-5', 5, 35.99, 30.24),
        ]);

        $product = $this->createSimpleProduct('prod-prio', 'Priority', 'SKU-PR', grossPrice: 39.99, netPrice: 33.60, tierPrices: $tierRows);

        $result = $formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(38.00, $result[0]['prices'][''][0]['current_price']);
        $this->assertSame([['min_quantity' => 10, 'price' => 32.00]], $result[0]['prices'][''][0]['tier_prices']);
    }

    public function testGuestRulesAreResolvedPerSalesChannelAndCurrency(): void
    {
        $resolver = $this->createMock(GuestRuleResolverInterface::class);
        $resolver->expects($this->once())
            ->method('getGuestRuleIds')
            ->with('sc-1', 'curr-eur')
            ->willReturn([self::PUBLIC_RULE]);
        $formatter = new ProductFormatter($this->config, $resolver);

        $tierRows = new ProductPriceCollection([$this->createTierRow('public-5', 5, 35.99, 30.24)]);
        $product = $this->createSimpleProduct('prod-sc', 'Channel', 'SKU-SC', grossPrice: 39.99, netPrice: 33.60, tierPrices: $tierRows);

        $formatter->formatProduct($product, $this->defaultChannelContexts);
    }

    public function testNoSalesChannelMeansNoTierPrices(): void
    {
        $contexts = ['' => [['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'languageId' => 'lang-en']]];
        $tierRows = new ProductPriceCollection([$this->createTierRow('public-5', 5, 35.99, 30.24)]);
        $product = $this->createSimpleProduct('prod-nosc', 'No channel', 'SKU-NSC', grossPrice: 39.99, netPrice: 33.60, tierPrices: $tierRows);

        $result = $this->formatter->formatProduct($product, $contexts);

        $this->assertArrayNotHasKey('tier_prices', $result[0]['prices'][''][0]);
    }

    public function testPriceEntrySendsGrossHeadlineWithInclExclWhenTaxApplies(): void
    {
        // Tax present (gross != net): headline is gross, and the incl/excl
        // pair is always included so the store's "Show tax info" dashboard
        // toggle can render the breakdown. Tiers use gross too.
        $tierRows = new ProductPriceCollection([
            $this->createTierRow('tier-base', 1, 39.99, 33.60),
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

    /**
     * A product priced only in the default currency is shown in other
     * currencies converted by the currency factor, like the storefront does.
     */
    public function testDefaultCurrencyFallbackIsConvertedWithCurrencyFactor(): void
    {
        $contexts = ['' => [['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'USD', 'currencyId' => 'curr-usd', 'currencyFactor' => '1.17085', 'currencyDecimals' => '2', 'currencyInterval' => '0.01', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en']]];
        $tierRows = new ProductPriceCollection([
            $this->createTierRow('public-1', 1, 5496.00, 4618.49, currencyId: Defaults::CURRENCY, listPrice: new Price(Defaults::CURRENCY, 5041.18, 5999.00, false)),
            $this->createTierRow('public-5', 5, 4999.00, 4200.84, currencyId: Defaults::CURRENCY),
        ]);
        $product = $this->createSimpleProduct(
            'prod-usd',
            'USD fallback',
            'SKU-USD',
            grossPrice: 5496.00,
            netPrice: 4618.49,
            tierPrices: $tierRows,
            priceCurrencyId: Defaults::CURRENCY,
            listPrice: new Price(Defaults::CURRENCY, 5041.18, 5999.00, false),
        );

        $result = $this->formatter->formatProduct($product, $contexts);

        $priceEntry = $result[0]['prices'][''][0];
        $this->assertSame('USD', $priceEntry['currency']);
        $this->assertSame(6434.99, $priceEntry['current_price']);
        $this->assertSame(7023.93, $priceEntry['regular_price']);
        $this->assertSame(5407.56, $priceEntry['price_excl_tax']);
        $this->assertSame([['min_quantity' => 5, 'price' => 5853.08]], $priceEntry['tier_prices']);
    }

    public function testOwnCurrencyPriceIsNotConverted(): void
    {
        $contexts = ['' => [['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'USD', 'currencyId' => 'curr-usd', 'currencyFactor' => '1.17085', 'currencyDecimals' => '2', 'currencyInterval' => '0.01', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en']]];
        $tierRows = new ProductPriceCollection([
            $this->createTierRow('public-1', 1, 5999.00, 5041.18, currencyId: 'curr-usd'),
            $this->createTierRow('public-5', 5, 5500.00, 4621.85, currencyId: 'curr-usd'),
        ]);
        $product = $this->createSimpleProduct('prod-own', 'Own USD', 'SKU-OWN', grossPrice: 5999.00, netPrice: 5041.18, tierPrices: $tierRows, priceCurrencyId: 'curr-usd');

        $result = $this->formatter->formatProduct($product, $contexts);

        $priceEntry = $result[0]['prices'][''][0];
        $this->assertSame(5999.00, $priceEntry['current_price']);
        $this->assertSame([['min_quantity' => 5, 'price' => 5500.00]], $priceEntry['tier_prices']);
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    private function foreignCurrencyContexts(string $iso, string $factor, string $decimals = '2', string $interval = '0.01'): array
    {
        return ['' => [[
            'languageCode' => 'en',
            'domainUrl' => 'https://shop.example.com',
            'currencyIso' => $iso,
            'currencyId' => 'curr-foreign',
            'currencyFactor' => $factor,
            'currencyDecimals' => $decimals,
            'currencyInterval' => $interval,
            'salesChannelId' => 'sc-1',
            'languageId' => 'lang-en',
        ]]];
    }

    public function testConvertedPricesUseTheCurrencyCashRoundingInterval(): void
    {
        $tierRows = new ProductPriceCollection([
            $this->createTierRow('public-1', 1, 99.99, 84.03, currencyId: Defaults::CURRENCY),
            $this->createTierRow('public-5', 5, 90.00, 75.63, currencyId: Defaults::CURRENCY),
        ]);
        $product = $this->createSimpleProduct('prod-chf', 'CHF', 'SKU-CHF', grossPrice: 99.99, netPrice: 84.03, tierPrices: $tierRows, priceCurrencyId: Defaults::CURRENCY);

        $result = $this->formatter->formatProduct($product, $this->foreignCurrencyContexts('CHF', '0.9371', interval: '0.05'));

        $priceEntry = $result[0]['prices'][''][0];
        // 99.99 * 0.9371 = 93.70 -> 93.70; 90.00 * 0.9371 = 84.339 -> 84.35
        $this->assertSame(93.70, $priceEntry['current_price']);
        $this->assertSame([['min_quantity' => 5, 'price' => 84.35]], $priceEntry['tier_prices']);
    }

    public function testConvertedPricesUseTheCurrencyDecimals(): void
    {
        $product = $this->createSimpleProduct('prod-jpy', 'JPY', 'SKU-JPY', grossPrice: 99.99, netPrice: 84.03, priceCurrencyId: Defaults::CURRENCY);

        $result = $this->formatter->formatProduct($product, $this->foreignCurrencyContexts('JPY', '171.23', decimals: '0', interval: '1'));

        $priceEntry = $result[0]['prices'][''][0];
        $this->assertSame(17121.0, $priceEntry['current_price']);
        $this->assertSame(14388.0, $priceEntry['price_excl_tax']);
    }

    public function testInvalidCurrencyFactorNeverZeroesPrices(): void
    {
        $product = $this->createSimpleProduct('prod-bad', 'Bad factor', 'SKU-BAD', grossPrice: 99.99, netPrice: 84.03, priceCurrencyId: Defaults::CURRENCY);

        foreach (['0', '', 'abc', '-1'] as $factor) {
            $result = $this->formatter->formatProduct($product, $this->foreignCurrencyContexts('USD', $factor));
            $this->assertSame(99.99, $result[0]['prices'][''][0]['current_price'], "factor '$factor'");
        }
    }

    public function testContextsWithoutCurrencyDataKeepTwoDecimalRounding(): void
    {
        $product = $this->createSimpleProduct('prod-legacy', 'Legacy ctx', 'SKU-LEG', grossPrice: 39.994, netPrice: 33.604);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(39.99, $result[0]['prices'][''][0]['current_price']);
        $this->assertSame(33.60, $result[0]['prices'][''][0]['price_excl_tax']);
    }

    /**
     * When a guest's rule has advanced prices, Shopware charges the lowest
     * quantity row for one unit and ignores the product's own price and list
     * price. Sending product.price made the chat quote a price the cart never
     * charges.
     */
    public function testGuestRuleQuantityOneRowIsTheCurrentPrice(): void
    {
        $tierRows = new ProductPriceCollection([
            $this->createTierRow('public-10', 10, 80.00, 67.23),
            $this->createTierRow('public-1', 1, 90.00, 75.63),
        ]);
        $product = $this->createSimpleProduct(
            'prod-rule-base',
            'Rule base',
            'SKU-RB',
            grossPrice: 100.00,
            netPrice: 84.03,
            tierPrices: $tierRows,
            listPrice: new Price('curr-eur', 100.84, 120.00, false),
        );

        $priceEntry = $this->formatter->formatProduct($product, $this->defaultChannelContexts)[0]['prices'][''][0];

        $this->assertSame(90.00, $priceEntry['current_price']);
        $this->assertSame(90.00, $priceEntry['regular_price']);
        $this->assertSame(90.00, $priceEntry['price_incl_tax']);
        $this->assertSame(75.63, $priceEntry['price_excl_tax']);
        $this->assertSame([['min_quantity' => 10, 'price' => 80.00]], $priceEntry['tier_prices']);
    }

    public function testGuestRuleListPriceIsTheRegularPrice(): void
    {
        $tierRows = new ProductPriceCollection([
            $this->createTierRow('public-1', 1, 90.00, 75.63, listPrice: new Price('curr-eur', 92.44, 110.00, false)),
        ]);
        $product = $this->createSimpleProduct('prod-rule-list', 'Rule list', 'SKU-RL', grossPrice: 100.00, netPrice: 84.03, tierPrices: $tierRows);

        $priceEntry = $this->formatter->formatProduct($product, $this->defaultChannelContexts)[0]['prices'][''][0];

        $this->assertSame(90.00, $priceEntry['current_price']);
        $this->assertSame(110.00, $priceEntry['regular_price']);
        $this->assertArrayNotHasKey('tier_prices', $priceEntry);
    }

    /**
     * The cart prices a quantity with the first row whose range covers it, so
     * a rule whose rows start above 1 (possible through the API) charges its
     * first row for a single unit as well.
     */
    public function testGuestRuleWhoseRowsStartAboveOneChargesTheFirstRowForOneUnit(): void
    {
        $tierRows = new ProductPriceCollection([
            $this->createTierRow('public-5', 5, 35.99, 30.24),
            $this->createTierRow('public-10', 10, 30.00, 25.21),
        ]);
        $product = $this->createSimpleProduct('prod-rule-5', 'Rule 5', 'SKU-R5', grossPrice: 39.99, netPrice: 33.60, tierPrices: $tierRows);

        $priceEntry = $this->formatter->formatProduct($product, $this->defaultChannelContexts)[0]['prices'][''][0];

        $this->assertSame(35.99, $priceEntry['current_price']);
        $this->assertSame([['min_quantity' => 10, 'price' => 30.00]], $priceEntry['tier_prices']);
    }

    /**
     * Products are loaded without inheritance, so a variant that inherits the
     * parent's price reads null. It used to ship with no price at all.
     */
    public function testVariantWithoutOwnPriceInheritsTheParentPrice(): void
    {
        $variant = $this->createVariantProduct('var-inherit', 'Variant', 'VAR-INH');
        $product = $this->createSimpleProduct('parent-inherit', 'Parent', 'PAR-INH', children: new ProductCollection([$variant]), grossPrice: 39.99, netPrice: 33.60);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(39.99, $result[1]['prices'][''][0]['current_price']);
        $this->assertSame(33.60, $result[1]['prices'][''][0]['price_excl_tax']);
    }

    public function testVariantOwnPriceWinsOverTheParentPrice(): void
    {
        $variant = $this->createVariantProduct('var-own', 'Variant', 'VAR-OWN', price: new PriceCollection([new Price('curr-eur', 42.02, 50.00, false)]));
        $product = $this->createSimpleProduct('parent-own', 'Parent', 'PAR-OWN', children: new ProductCollection([$variant]), grossPrice: 39.99, netPrice: 33.60);

        $result = $this->formatter->formatProduct($product, $this->defaultChannelContexts);

        $this->assertSame(39.99, $result[0]['prices'][''][0]['current_price']);
        $this->assertSame(50.00, $result[1]['prices'][''][0]['current_price']);
    }

    /**
     * Shopware inherits price and advanced prices separately: a variant with
     * its own price but no advanced prices is priced by the parent's.
     */
    public function testVariantWithoutOwnAdvancedPricesInheritsTheParentRules(): void
    {
        $parentRows = new ProductPriceCollection([
            $this->createTierRow('public-1', 1, 38.00, 31.93),
            $this->createTierRow('public-10', 10, 30.00, 25.21),
        ]);
        $variant = $this->createVariantProduct('var-rules', 'Variant', 'VAR-RUL', price: new PriceCollection([new Price('curr-eur', 42.02, 50.00, false)]));
        $product = $this->createSimpleProduct('parent-rules', 'Parent', 'PAR-RUL', children: new ProductCollection([$variant]), grossPrice: 39.99, netPrice: 33.60, tierPrices: $parentRows);

        $priceEntry = $this->formatter->formatProduct($product, $this->defaultChannelContexts)[1]['prices'][''][0];

        $this->assertSame(38.00, $priceEntry['current_price']);
        $this->assertSame([['min_quantity' => 10, 'price' => 30.00]], $priceEntry['tier_prices']);
    }

    public function testVariantOwnAdvancedPricesReplaceTheParentRules(): void
    {
        $parentRows = new ProductPriceCollection([$this->createTierRow('public-1', 1, 38.00, 31.93)]);
        $variantRows = new ProductPriceCollection([
            $this->createTierRow('var-1', 1, 45.00, 37.82),
            $this->createTierRow('var-5', 5, 41.00, 34.45),
        ]);
        $variant = $this->createVariantProduct('var-ownrules', 'Variant', 'VAR-OR', rulePrices: $variantRows);
        $product = $this->createSimpleProduct('parent-ownrules', 'Parent', 'PAR-OR', children: new ProductCollection([$variant]), grossPrice: 39.99, netPrice: 33.60, tierPrices: $parentRows);

        $priceEntry = $this->formatter->formatProduct($product, $this->defaultChannelContexts)[1]['prices'][''][0];

        $this->assertSame(45.00, $priceEntry['current_price']);
        $this->assertSame([['min_quantity' => 5, 'price' => 41.00]], $priceEntry['tier_prices']);
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

    private function createTierRow(string $id, int $quantityStart, float $gross, float $net, string $currencyId = 'curr-eur', string $ruleId = self::PUBLIC_RULE, ?Price $listPrice = null): ProductPriceEntity
    {
        $row = new ProductPriceEntity();
        $row->setId(str_pad($id, 32, '0'));
        $row->setUniqueIdentifier($id);
        $row->setRuleId($ruleId);
        $row->setQuantityStart($quantityStart);
        $row->setPrice(new PriceCollection([new Price($currencyId, $net, $gross, false, $listPrice)]));

        return $row;
    }

    /**
     * @param array<string, int> $levelBySalesChannel
     */
    private function visibilities(array $levelBySalesChannel): \Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityCollection
    {
        $collection = new \Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityCollection();
        foreach ($levelBySalesChannel as $salesChannelId => $level) {
            $visibility = new \Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityEntity();
            $visibility->setId('vis-' . $salesChannelId);
            $visibility->setSalesChannelId($salesChannelId);
            $visibility->setVisibility($level);
            $collection->add($visibility);
        }

        return $collection;
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
        string $priceCurrencyId = 'curr-eur',
        ?Price $listPrice = null,
    ): ProductEntity&MockObject {
        $price = $grossPrice !== null
            ? new PriceCollection([new Price($priceCurrencyId, $netPrice ?? $grossPrice, $grossPrice, false, $listPrice)])
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
        ?PriceCollection $price = null,
        ?ProductPriceCollection $rulePrices = null,
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
        $variant->method('getPrice')->willReturn($price);
        $variant->method('getPrices')->willReturn($rulePrices ?? new ProductPriceCollection());
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
