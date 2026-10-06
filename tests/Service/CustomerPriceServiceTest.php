<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\Service\ChannelResolverInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\CustomerPriceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\AbstractRuleLoader;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\ListPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\FieldVisibility;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CustomerPriceServiceTest extends TestCase
{
    private const CUSTOMER = '0190aaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PRODUCT = '0190cccccccccccccccccccccccccccc';
    private const PARENT = '0190dddddddddddddddddddddddddddd';
    private const VARIANT = '0190eeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private const STORE = 'st_1';
    private const SC_REQUEST = '0190f0f0f0f0f0f0f0f0f0f0f0f0f001';
    private const SC_MINE = '0190f0f0f0f0f0f0f0f0f0f0f0f0f002';
    private const SC_THEIRS = '0190f0f0f0f0f0f0f0f0f0f0f0f0f003';

    private AbstractSalesChannelContextFactory&MockObject $factory;
    private AbstractRuleLoader&MockObject $ruleLoader;
    private SalesChannelRepository&MockObject $products;
    private EntityRepository&MockObject $customers;
    private ConfigServiceInterface&MockObject $config;
    private ChannelResolverInterface&MockObject $channels;
    private Connection&MockObject $connection;
    private SalesChannelContext&MockObject $customerContext;
    private string $taxState = CartPrice::TAX_STATE_GROSS;

    /** @var list<array<string, mixed>> */
    private array $createdWith = [];

    /** @var list<SalesChannelProductEntity> */
    private array $catalog = [];

    protected function setUp(): void
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $this->customerContext = $this->createMock(SalesChannelContext::class);
        $this->customerContext->method('getToken')->willReturn('fresh-token');
        $this->customerContext->method('getContext')->willReturn(Context::createDefaultContext());
        $this->customerContext->method('getCurrency')->willReturn($currency);
        $this->customerContext->method('getItemRounding')->willReturn(new CashRoundingConfig(2, 0.01, true));
        $this->customerContext->method('getTaxState')->willReturnCallback(fn () => $this->taxState);

        $this->factory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $this->factory->method('create')->willReturnCallback(function (string $token, string $salesChannelId, array $options) {
            $this->createdWith[] = ['salesChannelId' => $salesChannelId, 'options' => $options];

            return $this->customerContext;
        });

        $this->ruleLoader = $this->createMock(AbstractRuleLoader::class);
        $this->ruleLoader->method('load')->willReturn(new RuleCollection([
            $this->rule('rule-dealer', true),
            $this->rule('rule-other', false),
        ]));

        $this->products = $this->createMock(SalesChannelRepository::class);
        $this->products->method('search')->willReturnCallback(function (Criteria $criteria, SalesChannelContext $context) {
            $ids = $criteria->getIds();
            $found = array_filter($this->catalog, fn (SalesChannelProductEntity $p) => $ids === []
                ? $p->getParentId() !== null
                : \in_array($p->getId(), $ids, true));

            return new EntitySearchResult('product', \count($found), new ProductCollection($found), null, $criteria, $context->getContext());
        });

        $this->customers = $this->createMock(EntityRepository::class);
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->channels = $this->createMock(ChannelResolverInterface::class);
        $this->connection = $this->createMock(Connection::class);
    }

    // --- request parsing ---

    public function testProductIdsAcceptOurPrefixesAndDropTheRest(): void
    {
        $ids = CustomerPriceService::productIds(['products' => ['product-' . self::PRODUCT, 'variation-' . strtoupper(self::VARIANT), self::PARENT, 'sku-1', 42, 'product-' . self::PRODUCT]]);

        $this->assertSame([self::PRODUCT, self::VARIANT, self::PARENT], $ids);
    }

    public function testProductListMustHoldOneToTwentyIds(): void
    {
        $this->assertNull(CustomerPriceService::productIds(['products' => []]));
        $this->assertNull(CustomerPriceService::productIds([]));
        $this->assertNull(CustomerPriceService::productIds(['products' => array_fill(0, 21, self::PRODUCT)]));
        $this->assertNotNull(CustomerPriceService::productIds(['products' => array_fill(0, 20, self::PRODUCT)]));
    }

    public function testCustomerIdComesOnlyFromTheVerifiedCustomer(): void
    {
        $this->assertSame(self::CUSTOMER, CustomerPriceService::customerId(['customer' => ['id' => ' ' . strtoupper(self::CUSTOMER) . ' ']]));
        $this->assertNull(CustomerPriceService::customerId(['customer' => null]));
        $this->assertNull(CustomerPriceService::customerId(['customer' => ['id' => 77]]));
    }

    // --- customers ---

    public function testUnknownOrInactiveCustomerIsNotFoundWithoutPricing(): void
    {
        $this->customerExists(false);
        $this->factory->expects($this->never())->method('create');

        $this->assertSame(['status' => 'not_found'], $this->service()->handle([], self::CUSTOMER, [self::PRODUCT], $this->requestContext(), self::STORE));
    }

    public function testMalformedCustomerIdIsNotFound(): void
    {
        $this->customers->expects($this->never())->method('searchIds');

        $this->assertSame(['status' => 'not_found'], $this->service()->handle([], 'not-a-uuid', [self::PRODUCT], $this->requestContext(), self::STORE));
    }

    // --- pricing ---

    /**
     * Current is the first advanced-price row (what one unit costs in the
     * cart); tiers start one above the previous row's last quantity.
     */
    public function testPricesAreTheCalculatedPricesOfTheCustomersContext(): void
    {
        $this->customerExists(true);
        $this->catalog = [$this->product(self::PRODUCT, null, 80.0, [[70.0, 4, 84.0], [60.0, null, null]])];

        $result = $this->service()->handle(['currency' => 'EUR'], self::CUSTOMER, [self::PRODUCT], $this->requestContext(), self::STORE);

        $this->assertSame('found', $result['status']);
        $this->assertSame('EUR', $result['data']['currency']);
        $this->assertTrue($result['data']['prices_include_tax']);
        $this->assertSame([
            'current_price' => 70.0,
            'regular_price' => 84.0,
            'price_incl_tax' => 70.0,
            'price_excl_tax' => 58.82,
            'prices_include_tax' => true,
            'tier_prices' => [['min_quantity' => 5, 'price' => 60.0]],
        ], $result['data']['products']['product-' . self::PRODUCT]);
    }

    public function testWithoutAdvancedPricesTheProductPriceIsUsed(): void
    {
        $this->customerExists(true);
        $this->catalog = [$this->product(self::PRODUCT, null, 80.0, [])];

        $entry = $this->service()->handle([], self::CUSTOMER, [self::PRODUCT], $this->requestContext(), self::STORE)['data']['products']['product-' . self::PRODUCT];

        $this->assertSame(80.0, $entry['current_price']);
        $this->assertSame(80.0, $entry['regular_price']);
        $this->assertArrayNotHasKey('tier_prices', $entry);
    }

    public function testANetCustomerGroupIsAnsweredNet(): void
    {
        $this->taxState = CartPrice::TAX_STATE_NET;
        $this->customerExists(true);
        $this->catalog = [$this->product(self::PRODUCT, null, 50.0, [], 0.19)];

        $result = $this->service()->handle([], self::CUSTOMER, [self::PRODUCT], $this->requestContext(), self::STORE);
        $entry = $result['data']['products']['product-' . self::PRODUCT];

        $this->assertFalse($result['data']['prices_include_tax']);
        $this->assertFalse($entry['prices_include_tax']);
        $this->assertSame(50.0, $entry['current_price']);
        $this->assertSame(59.5, $entry['price_incl_tax']);
        $this->assertSame(50.0, $entry['price_excl_tax']);
    }

    public function testAParentIsAnsweredWithItsVisibleVariants(): void
    {
        $this->customerExists(true);
        $this->catalog = [
            $this->product(self::PARENT, null, 20.0, [], 0.19, 2),
            $this->product(self::VARIANT, self::PARENT, 25.0, []),
        ];

        $entry = $this->service()->handle([], self::CUSTOMER, [self::PARENT], $this->requestContext(), self::STORE)['data']['products']['product-' . self::PARENT];

        $this->assertSame(25.0, $entry['variations']['variation-' . self::VARIANT]['current_price']);
        $this->assertTrue($entry['variations']['variation-' . self::VARIANT]['prices_include_tax']);
    }

    /**
     * A product the customer cannot see (not loaded by the storefront product
     * repository) is left out, never reported as an error.
     */
    public function testHiddenProductsAreOmitted(): void
    {
        $this->customerExists(true);
        $this->catalog = [];

        $result = $this->service()->handle([], self::CUSTOMER, [self::PRODUCT], $this->requestContext(), self::STORE);

        $this->assertSame('found', $result['status']);
        $this->assertSame('{}', json_encode($result['data']['products']));
    }

    // --- context ---

    /**
     * The customer's own matched rules price the products, on a fresh context
     * built for this call; the request's context is never changed, so nothing
     * has to be restored and nothing can leak into the shop's session.
     */
    public function testRulesAreAppliedToAFreshContextOnly(): void
    {
        $this->customerExists(true);
        $this->customerContext->expects($this->once())->method('setRuleIds')->with(['rule-dealer' => 'rule-dealer']);
        $request = $this->requestContext();
        $request->expects($this->never())->method('setRuleIds');
        $request->expects($this->never())->method('setAreaRuleIds');

        $this->service()->handle([], self::CUSTOMER, [self::PRODUCT], $request, self::STORE);

        $this->assertSame(self::CUSTOMER, $this->createdWith[0]['options'][SalesChannelContextService::CUSTOMER_ID]);
        $this->assertNotSame('request-token', $this->createdWith[0]['options'][SalesChannelContextService::CUSTOMER_ID]);
    }

    public function testAnExceptionLeavesTheRequestContextUntouched(): void
    {
        $this->customerExists(true);
        $products = $this->createMock(SalesChannelRepository::class);
        $products->method('search')->willThrowException(new \RuntimeException('boom'));
        $request = $this->requestContext();
        $request->expects($this->never())->method('setRuleIds');

        $this->expectException(\RuntimeException::class);
        $this->service($products)->handle([], self::CUSTOMER, [self::PRODUCT], $request, self::STORE);
    }

    public function testASoldCurrencyIsUsedAndAnotherFallsBackToTheChannelDefault(): void
    {
        $this->customerExists(true);
        $this->connection->method('fetchOne')->willReturnCallback(fn (string $sql, array $p) => $p['iso'] === 'USD' ? 'usd-id' : false);

        $this->service()->handle(['currency' => 'usd'], self::CUSTOMER, [self::PRODUCT], $this->requestContext(), self::STORE);
        $this->service()->handle(['currency' => 'JPY'], self::CUSTOMER, [self::PRODUCT], $this->requestContext(), self::STORE);

        $this->assertSame('usd-id', $this->createdWith[0]['options'][SalesChannelContextService::CURRENCY_ID]);
        $this->assertArrayNotHasKey(SalesChannelContextService::CURRENCY_ID, $this->createdWith[1]['options']);
    }

    public function testTheNamedChannelIsUsedOnlyWhenItBelongsToTheSigningStore(): void
    {
        $this->customerExists(true);
        $this->channels->method('getMapping')->willReturn([self::SC_MINE => 'retail', self::SC_THEIRS => 'wholesale']);
        $this->config->method('getEnabledSalesChannels')->willReturn([]);
        $this->config->method('getStoreId')->willReturnCallback(fn (?string $id) => $id === self::SC_THEIRS ? 'st_2' : self::STORE);

        $this->service()->handle(['channel' => 'retail'], self::CUSTOMER, [self::PRODUCT], $this->requestContext(), self::STORE);
        $this->service()->handle(['channel' => 'wholesale'], self::CUSTOMER, [self::PRODUCT], $this->requestContext(), self::STORE);

        $this->assertSame(self::SC_MINE, $this->createdWith[0]['salesChannelId']);
        $this->assertSame(self::SC_REQUEST, $this->createdWith[1]['salesChannelId']);
    }

    // --- helpers ---

    private function service(?SalesChannelRepository $products = null): CustomerPriceService
    {
        return new CustomerPriceService(
            $this->factory,
            $this->ruleLoader,
            $products ?? $this->products,
            $this->customers,
            $this->config,
            $this->channels,
            $this->connection,
        );
    }

    private function requestContext(): SalesChannelContext&MockObject
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SC_REQUEST);
        $context->method('getToken')->willReturn('request-token');

        return $context;
    }

    private function customerExists(bool $exists): void
    {
        $result = $this->createMock(IdSearchResult::class);
        $result->method('getTotal')->willReturn($exists ? 1 : 0);
        $this->customers->method('searchIds')->willReturn($result);
    }

    private function rule(string $id, bool $match): RuleEntity
    {
        $payload = $this->createMock(Rule::class);
        $payload->method('match')->willReturn($match);
        $rule = new RuleEntity();
        $rule->setId($id);
        $rule->setUniqueIdentifier($id);
        $rule->setPriority(1);
        $rule->setPayload($payload);
        $rule->setAreas([]);

        return $rule;
    }

    /**
     * @param list<array{0: float, 1: ?int, 2: ?float}> $rows advanced-price rows: unit price, last quantity, list price
     */
    private function product(string $id, ?string $parentId, float $price, array $rows, float $rate = 0.19, int $childCount = 0): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        // As the DAL hydrates it, so the search result's entity name matches
        $product->internalSetEntityData('product', new FieldVisibility([]));
        $product->setId($id);
        $product->setUniqueIdentifier($id);
        $product->setParentId($parentId);
        $product->setChildCount($childCount);
        $product->setCalculatedPrice($this->price($price, 1, null, $rate));
        $calculated = new PriceCollection();
        foreach ($rows as [$unit, $last, $list]) {
            $calculated->add($this->price($unit, $last ?? ($calculated->count() > 0 ? $calculated->last()->getQuantity() + 1 : 1), $list, $rate));
        }
        $product->setCalculatedPrices($calculated);

        return $product;
    }

    private function price(float $unit, int $quantity, ?float $list, float $rate): CalculatedPrice
    {
        $total = $unit * $quantity;
        $tax = $this->taxState === CartPrice::TAX_STATE_NET ? $total * $rate : $total - $total / (1 + $rate);

        return new CalculatedPrice(
            $unit,
            $total,
            new CalculatedTaxCollection([new CalculatedTax($tax, $rate * 100, $total)]),
            new TaxRuleCollection(),
            $quantity,
            null,
            $list !== null ? ListPrice::createFromUnitPrice($unit, $list) : null,
        );
    }
}
