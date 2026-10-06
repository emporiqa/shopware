<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\AbstractRuleLoader;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * The `customer_prices` ready-made rule: what one signed-in customer pays.
 *
 * Prices come from Shopware's own price engine: a sales channel context is
 * built for the customer (their group's net/gross display, their address's
 * tax rules, the currency), the rules they match are evaluated as the cart
 * does for an empty cart, and the products are loaded through the storefront
 * product repository, which applies visibility and calculates the prices
 * exactly as the product page and the cart do. The context is a fresh object
 * for this call only: nothing is persisted, no cart or session is touched.
 */
class CustomerPriceService
{
    public const MAX_PRODUCTS = 20;

    /** Variants answered per requested parent product. */
    private const MAX_VARIATIONS = 100;

    /**
     * @param EntityRepository<CustomerCollection> $customerRepository
     * @param SalesChannelRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly AbstractRuleLoader $ruleLoader,
        private readonly SalesChannelRepository $productRepository,
        private readonly EntityRepository $customerRepository,
        private readonly ConfigServiceInterface $config,
        private readonly ChannelResolverInterface $channelResolver,
        private readonly Connection $connection,
    ) {
    }

    /**
     * The verified customer id, or null when the request carries none.
     *
     * @param array<string, mixed> $payload
     */
    public static function customerId(array $payload): ?string
    {
        $customer = $payload['customer'] ?? null;
        $id = \is_array($customer) && \is_string($customer['id'] ?? null) ? strtolower(trim($customer['id'])) : '';

        return $id !== '' ? $id : null;
    }

    /**
     * The requested product ids as Shopware ids, or null when the list is
     * missing, empty or too long. Ids that are not ours are dropped: they
     * are simply not answered.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>|null
     */
    public static function productIds(array $payload): ?array
    {
        $products = $payload['products'] ?? null;
        if (!\is_array($products) || $products === [] || \count($products) > self::MAX_PRODUCTS) {
            return null;
        }

        $ids = [];
        foreach ($products as $value) {
            $id = \is_string($value) ? strtolower(trim($value)) : '';
            $id = (string) preg_replace('/^(product|variation)-/', '', $id);
            if (Uuid::isValid($id)) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<string, mixed> $payload decoded, signature-verified request body
     * @param list<string> $productIds from productIds()
     *
     * @return array<string, mixed> response envelope
     */
    public function handle(array $payload, string $customerId, array $productIds, SalesChannelContext $requestContext, string $storeId): array
    {
        $salesChannelId = $this->salesChannelId($payload, $requestContext, $storeId);
        if (!Uuid::isValid($customerId) || !$this->customerExists($customerId, $salesChannelId, $requestContext->getContext())) {
            return ['status' => 'not_found'];
        }

        $options = [SalesChannelContextService::CUSTOMER_ID => $customerId];
        $currencyId = $this->currencyId($payload, $salesChannelId);
        if ($currencyId !== null) {
            $options[SalesChannelContextService::CURRENCY_ID] = $currencyId;
        }

        $context = $this->contextFactory->create(Uuid::randomHex(), $salesChannelId, $options);
        $this->applyMatchingRules($context);

        $products = [];
        foreach ($this->load($productIds, $context) as $product) {
            if (!$product instanceof SalesChannelProductEntity) {
                continue;
            }
            $entry = $this->priceEntry($product, $context);
            if ($product->getParentId() === null && $product->getChildCount() > 0) {
                $variations = [];
                foreach ($this->loadVariants($product->getId(), $context) as $variant) {
                    if (!$variant instanceof SalesChannelProductEntity) {
                        continue;
                    }
                    $variations['variation-' . $variant->getId()] = $this->priceEntry($variant, $context);
                }
                if ($variations !== []) {
                    $entry['variations'] = $variations;
                }
            }
            $products[($product->getParentId() === null ? 'product-' : 'variation-') . $product->getId()] = $entry;
        }

        return [
            'status' => 'found',
            'data' => [
                'currency' => $context->getCurrency()->getIsoCode(),
                'prices_include_tax' => $context->getTaxState() === CartPrice::TAX_STATE_GROSS,
                'products' => $products === [] ? new \stdClass() : $products,
            ],
        ];
    }

    /**
     * The sales channel the request names (Emporiqa channel key), when it is
     * synced and belongs to the signing store; otherwise the one the request
     * came in on, which authenticated as that store.
     *
     * @param array<string, mixed> $payload
     */
    private function salesChannelId(array $payload, SalesChannelContext $requestContext, string $storeId): string
    {
        $channel = \is_string($payload['channel'] ?? null) ? $payload['channel'] : '';
        if ($channel !== '') {
            $enabled = $this->config->getEnabledSalesChannels();
            foreach ($this->channelResolver->getMapping() as $salesChannelId => $key) {
                $salesChannelId = (string) $salesChannelId;
                if (
                    $key === $channel
                    && ($enabled === [] || \in_array($salesChannelId, $enabled, true))
                    && $this->config->getStoreId($salesChannelId) === $storeId
                ) {
                    return $salesChannelId;
                }
            }
        }

        return $requestContext->getSalesChannelId();
    }

    /**
     * An active customer who may sign in on this sales channel, as Shopware's
     * own context factory accepts them.
     */
    private function customerExists(string $customerId, string $salesChannelId, Context $context): bool
    {
        $criteria = new Criteria([$customerId]);
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('boundSalesChannelId', null),
            new EqualsFilter('boundSalesChannelId', $salesChannelId),
        ]));

        return $this->customerRepository->searchIds($criteria, $context)->getTotal() > 0;
    }

    /**
     * The requested currency when the sales channel sells in it, otherwise
     * null for the channel's default.
     *
     * @param array<string, mixed> $payload
     */
    private function currencyId(array $payload, string $salesChannelId): ?string
    {
        $iso = \is_string($payload['currency'] ?? null) ? strtoupper(trim($payload['currency'])) : '';
        if (!preg_match('/^[A-Z]{3}$/D', $iso)) {
            return null;
        }

        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(c.`id`)) FROM `currency` c'
            . ' INNER JOIN `sales_channel_currency` scc ON scc.`currency_id` = c.`id`'
            . ' WHERE scc.`sales_channel_id` = :salesChannelId AND c.`iso_code` = :iso',
            ['salesChannelId' => Uuid::fromHexToBytes($salesChannelId), 'iso' => $iso],
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The rules this customer matches, highest priority first, evaluated the
     * way the cart evaluates them for an empty cart (CartRuleLoader), without
     * loading or saving a cart. Product prices only read the rule ids, not
     * the per-area ids promotions and shipping use.
     */
    private function applyMatchingRules(SalesChannelContext $context): void
    {
        $rules = $this->ruleLoader->load($context->getContext())->filterForContext()
            ->filterMatchingRules(new Cart($context->getToken()), $context);

        $context->setRuleIds($rules->getIds());
    }

    /**
     * @param list<string> $ids
     */
    private function load(array $ids, SalesChannelContext $context): ProductCollection
    {
        $criteria = new Criteria($ids);
        $criteria->addAssociation('prices');

        return $this->productRepository->search($criteria, $context)->getEntities();
    }

    private function loadVariants(string $parentId, SalesChannelContext $context): ProductCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('parentId', [$parentId]));
        $criteria->addAssociation('prices');
        $criteria->addSorting(new FieldSorting('productNumber'));
        $criteria->setLimit(self::MAX_VARIATIONS);

        return $this->productRepository->search($criteria, $context)->getEntities();
    }

    /**
     * Same semantics as the synced price: current is what one unit costs in
     * the cart (the first advanced-price row when the customer's rule has
     * any, ProductCartProcessor::getPriceDefinition), tiers are the unit price
     * the cart charges from each row's first quantity.
     *
     * @return array<string, mixed>
     */
    private function priceEntry(SalesChannelProductEntity $product, SalesChannelContext $context): array
    {
        $rows = array_values($product->getCalculatedPrices()->getElements());
        $unit = $rows[0] ?? $product->getCalculatedPrice();
        $current = $unit->getUnitPrice();
        [$incl, $excl] = $this->inclExcl($unit, $context);

        $list = $unit->getListPrice()?->getPrice();
        $entry = [
            'current_price' => $current,
            // Shopware leaves a net list price unrounded; the storefront shows it rounded.
            'regular_price' => $list !== null ? round($list, $context->getItemRounding()->getDecimals()) : $current,
            'price_incl_tax' => $incl,
            'price_excl_tax' => $excl,
            // One tax display per customer group in Shopware, so every entry
            // shares the context's; the top-level flag is the same value.
            'prices_include_tax' => $context->getTaxState() === CartPrice::TAX_STATE_GROSS,
        ];

        $tiers = [];
        for ($i = 1, $n = \count($rows); $i < $n; ++$i) {
            $tiers[] = ['min_quantity' => $rows[$i - 1]->getQuantity() + 1, 'price' => $rows[$i]->getUnitPrice()];
        }
        if ($tiers !== []) {
            $entry['tier_prices'] = $tiers;
        }

        return $entry;
    }

    /**
     * @return array{0: float, 1: float} one unit with and without tax
     */
    private function inclExcl(CalculatedPrice $price, SalesChannelContext $context): array
    {
        $unit = $price->getUnitPrice();
        $quantity = max(1, $price->getQuantity());
        $tax = $price->getCalculatedTaxes()->getAmount() / $quantity;
        $decimals = $context->getItemRounding()->getDecimals();

        return match ($context->getTaxState()) {
            CartPrice::TAX_STATE_GROSS => [$unit, round($unit - $tax, $decimals)],
            CartPrice::TAX_STATE_NET => [round($unit + $tax, $decimals), $unit],
            default => [$unit, $unit],
        };
    }
}
