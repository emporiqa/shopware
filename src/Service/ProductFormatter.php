<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceCollection;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;

class ProductFormatter implements ProductFormatterInterface
{
    /** Product state flag for digital/download products (Shopware\Core\Content\Product\State::IS_DOWNLOAD, deprecated class). */
    private const PRODUCT_STATE_DOWNLOAD = 'is-download';

    use TranslationResolverTrait;

    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly GuestRuleResolverInterface $guestRuleResolver,
        private readonly CashRounding $cashRounding = new CashRounding(),
    ) {
    }

    /**
     * @param array<string, array<int, array<string, string>>> $channelContexts Grouped by channel key
     * @return array<int, array<string, mixed>>
     */
    public function formatProduct(
        ProductEntity $product,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): array {
        $productId = $product->getId();
        $parentSku = $product->getProductNumber();
        $defaultCategory = '';
        $defaultBrand = '';

        // Filter channels by product visibility (if loaded)
        $channelContexts = $this->filterByVisibility($product, $channelContexts);
        if (empty($channelContexts)) {
            return [];
        }

        $children = $product->getChildren();
        $hasVariations = $children !== null && $children->count() > 0;

        $channelKeys = array_keys($channelContexts);

        $names = [];
        $descriptions = [];
        $links = [];
        $categories = [];
        $brands = [];
        $prices = [];
        $availabilityStatuses = [];
        $stockQuantities = [];
        $images = [];
        $attributes = [];
        $minOrderQuantities = [];
        $maxOrderQuantities = [];

        $parentMinPurchase = $product->getMinPurchase() ?? 1;
        $parentMaxPurchase = $product->getMaxPurchase();
        $parentIsCloseout = $product->getIsCloseout();

        // Availability is channel-independent; compute once and reuse per channel
        if ($hasVariations) {
            [$availability, $stock] = $this->aggregateChildAvailability($children, $parentIsCloseout);
        } else {
            $stock = $product->getAvailableStock() ?? $product->getStock();
            $availability = $this->resolveAvailability($stock, $parentIsCloseout);
        }

        foreach ($channelContexts as $channelKey => $contexts) {
            $channelNames = [];
            $channelDescriptions = [];
            $channelLinks = [];
            $channelAttributes = [];
            $channelCategories = [];

            // Use first language context for per-channel fields (brands)
            $primaryLanguageId = $contexts[0]['languageId'] ?? '';

            foreach ($contexts as $ctx) {
                $langCode = $ctx['languageCode'];
                $languageId = $ctx['languageId'] ?? '';
                $domainUrl = $ctx['domainUrl'];

                // Per-language fields: only set once per language (first currency/domain wins)
                if (!isset($channelNames[$langCode])) {
                    $channelNames[$langCode] = $this->getTranslatedString($product, 'name', $languageId);
                    $channelDescriptions[$langCode] = $this->getTranslatedString($product, 'description', $languageId);
                    $channelLinks[$langCode] = $this->getProductUrl($product, $domainUrl, $ctx['salesChannelId'] ?? null, $languageId ?: null);
                    $channelAttributes[$langCode] = $this->getPropertyAttributes($product, $languageId) ?: new \stdClass();
                    $channelCategories[$langCode] = $this->getCategoryHierarchy($product, $defaultCategory, $languageId);
                }
            }

            $names[$channelKey] = $channelNames;
            $descriptions[$channelKey] = $channelDescriptions;
            $links[$channelKey] = $channelLinks;
            $categories[$channelKey] = $channelCategories;
            $brands[$channelKey] = $this->getBrand($product, $defaultBrand, $primaryLanguageId);
            $attributes[$channelKey] = $channelAttributes;
            $images[$channelKey] = $this->getImages($product);

            $channelPrices = [];
            $seenCurrencies = [];
            foreach ($contexts as $ctx) {
                $currencyIso = $ctx['currencyIso'];
                if (isset($seenCurrencies[$currencyIso])) {
                    continue;
                }
                $seenCurrencies[$currencyIso] = true;

                $priceEntry = $this->buildPriceEntry($product->getPrice(), $product->getPrices(), $currencyIso, $ctx['currencyId'] ?? null, $ctx['salesChannelId'] ?? null, (float) ($ctx['currencyFactor'] ?? 1.0), $this->itemRounding($ctx));
                if ($priceEntry !== null) {
                    $channelPrices[] = $priceEntry;
                }
            }
            $prices[$channelKey] = $channelPrices;

            $availabilityStatuses[$channelKey] = $availability;
            $stockQuantities[$channelKey] = $stock;
            $minOrderQuantities[$channelKey] = $parentMinPurchase;
            $maxOrderQuantities[$channelKey] = $parentMaxPurchase;
        }

        $variationAttributes = [];
        if ($hasVariations) {
            foreach ($channelContexts as $channelKey => $contexts) {
                $channelVarAttrs = [];
                foreach ($contexts as $ctx) {
                    $langCode = $ctx['languageCode'];
                    $languageId = $ctx['languageId'] ?? '';
                    if (!isset($channelVarAttrs[$langCode])) {
                        $channelVarAttrs[$langCode] = $this->getVariationAttributeNamesFromChildren($children, $languageId);
                    }
                }
                $variationAttributes[$channelKey] = $channelVarAttrs;
            }
        }

        $parentData = [
            'identification_number' => 'product-' . $productId,
            'sku' => $product->getProductNumber(),
            'channels' => $channelKeys,
            'names' => $names,
            'descriptions' => $descriptions,
            'links' => $links,
            'categories' => $categories,
            'brands' => $brands,
            'prices' => $prices,
            'availability_statuses' => $availabilityStatuses,
            'stock_quantities' => $stockQuantities,
            'images' => $images,
            'attributes' => $attributes,
            'parent_sku' => null,
            'is_parent' => $hasVariations,
            'variation_attributes' => empty($variationAttributes) ? new \stdClass() : $variationAttributes,
            'min_order_quantities' => $minOrderQuantities,
            'max_order_quantities' => $maxOrderQuantities,
            'available_for_order' => true,
            'condition' => null,
            'is_virtual' => $this->isVirtual($product),
        ];

        if ($syncSessionId !== null) {
            $parentData['sync_session_id'] = $syncSessionId;
        }

        $result = [$parentData];

        if ($children !== null) {
            foreach ($children as $child) {
                // Inactive variants must not ship in full payloads (mirrors
                // the active-check used for availability events).
                if (($child->getActive() ?? true) === false) {
                    continue;
                }

                $variationData = $this->formatVariation(
                    $child,
                    $parentSku,
                    $names,
                    $images,
                    $channelContexts,
                    $syncSessionId,
                    $parentMinPurchase,
                    $parentMaxPurchase,
                    $parentIsCloseout,
                    $product->getPrice(),
                    $product->getPrices(),
                );

                $result[] = $variationData;
            }
        }

        return $result;
    }

    public function formatProductDelete(string $productId): array
    {
        return [
            ['identification_number' => 'product-' . $productId],
        ];
    }

    /**
     * @param array<string, array<int, array<string, string>>> $channelContexts Grouped by channel key
     * @return array<int, array<string, mixed>>
     */
    public function formatAvailabilityEvents(ProductEntity $product, array $channelContexts): array
    {
        $channelContexts = $this->filterByVisibility($product, $channelContexts);
        if (empty($channelContexts)) {
            return [];
        }

        $channelKeys = array_keys($channelContexts);

        // A variant passed directly (e.g. stock-only writes route the child
        // straight through without loading its parent) is never itself a
        // "has children" parent, it must report under the variation-
        // identifier the catalog already knows it by, not product-. The
        // parent entity isn't available here, so closeout falls back to the
        // variant's own raw value (null → false) rather than a parent value.
        if ($product->getParentId() !== null) {
            $productId = $product->getId();
            $stock = $product->getAvailableStock() ?? $product->getStock();

            return [
                $this->buildAvailabilityEntry(
                    'variation-' . $productId,
                    $product->getProductNumber(),
                    $channelKeys,
                    $this->resolveAvailability($stock, $this->resolveIsCloseout($product, false)),
                    $stock,
                ),
            ];
        }

        $children = $product->getChildren();
        $hasVariations = $children !== null && $children->count() > 0;
        $parentIsCloseout = $product->getIsCloseout();

        if (!$hasVariations) {
            $productId = $product->getId();
            $stock = $product->getAvailableStock() ?? $product->getStock();

            return [
                $this->buildAvailabilityEntry(
                    'product-' . $productId,
                    $product->getProductNumber(),
                    $channelKeys,
                    $this->resolveAvailability($stock, $parentIsCloseout),
                    $stock,
                ),
            ];
        }

        // One entry per active variation; Emporiqa derives the parent aggregate itself
        $events = [];
        foreach ($children as $child) {
            if (($child->getActive() ?? true) === false) {
                continue;
            }

            $childId = $child->getId();
            $stock = $child->getAvailableStock() ?? $child->getStock();

            $events[] = $this->buildAvailabilityEntry(
                'variation-' . $childId,
                $child->getProductNumber(),
                $channelKeys,
                $this->resolveAvailability($stock, $this->resolveIsCloseout($child, $parentIsCloseout)),
                $stock,
            );
        }

        return $events;
    }

    /**
     * A variation is stored by Emporiqa as a lean row: descriptions,
     * categories and brands are inherited from the parent, and
     * variation_attributes / is_parent are fixed. They are not sent.
     *
     * @param array<string, array<string, string>> $parentNames
     * @param array<string, string[]> $parentImages
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, mixed>
     */
    private function formatVariation(
        ProductEntity $variant,
        string $parentSku,
        array $parentNames,
        array $parentImages,
        array $channelContexts,
        ?string $syncSessionId,
        int $parentMinPurchase = 1,
        ?int $parentMaxPurchase = null,
        bool $parentIsCloseout = false,
        ?PriceCollection $parentPrice = null,
        ?ProductPriceCollection $parentRulePrices = null,
    ): array {
        $variantId = $variant->getId();
        $channelKeys = array_keys($channelContexts);

        $names = [];
        $links = [];
        $attributes = [];
        $prices = [];
        $availabilityStatuses = [];
        $stockQuantities = [];
        $images = [];
        $minOrderQuantities = [];
        $maxOrderQuantities = [];

        $minPurchase = $variant->getMinPurchase() ?? $parentMinPurchase;
        $maxPurchase = $variant->getMaxPurchase() ?? $parentMaxPurchase;
        $stock = $variant->getAvailableStock() ?? $variant->getStock();
        $availability = $this->resolveAvailability($stock, $this->resolveIsCloseout($variant, $parentIsCloseout));

        // Products are loaded without considerInheritance, so a variant that
        // inherits its price or advanced prices from the parent reads them as
        // empty. Shopware inherits each of the two fields on its own: a variant
        // with its own price but no advanced prices is still priced by the
        // parent's advanced prices in the storefront.
        $variantPrice = $variant->getPrice() ?? $parentPrice;
        $variantRulePrices = $variant->getPrices();
        if ($variantRulePrices === null || $variantRulePrices->count() === 0) {
            $variantRulePrices = $parentRulePrices;
        }

        foreach ($channelContexts as $channelKey => $contexts) {
            $channelNames = [];
            $channelLinks = [];
            $channelAttributes = [];

            foreach ($contexts as $ctx) {
                $langCode = $ctx['languageCode'];
                $languageId = $ctx['languageId'] ?? '';
                $domainUrl = $ctx['domainUrl'];

                // Per-language fields: only set once per language
                if (!isset($channelNames[$langCode])) {
                    $variantOptionAttrs = $this->getOptionAttributes($variant, $languageId);
                    $optionSuffix = !empty($variantOptionAttrs) ? ' - ' . implode(' / ', array_values($variantOptionAttrs)) : '';

                    $baseName = $parentNames[$channelKey][$langCode] ?? $this->getTranslatedString($variant, 'name', $languageId);
                    $channelNames[$langCode] = $baseName . $optionSuffix;
                    $channelLinks[$langCode] = $this->getProductUrl($variant, $domainUrl, $ctx['salesChannelId'] ?? null, $languageId ?: null);
                    $channelAttributes[$langCode] = !empty($variantOptionAttrs) ? $variantOptionAttrs : new \stdClass();
                }
            }

            $names[$channelKey] = $channelNames;
            $links[$channelKey] = $channelLinks;
            $attributes[$channelKey] = $channelAttributes;

            $channelPrices = [];
            $seenCurrencies = [];
            foreach ($contexts as $ctx) {
                $currencyIso = $ctx['currencyIso'];
                if (isset($seenCurrencies[$currencyIso])) {
                    continue;
                }
                $seenCurrencies[$currencyIso] = true;
                $priceEntry = $this->buildPriceEntry($variantPrice, $variantRulePrices, $currencyIso, $ctx['currencyId'] ?? null, $ctx['salesChannelId'] ?? null, (float) ($ctx['currencyFactor'] ?? 1.0), $this->itemRounding($ctx));
                if ($priceEntry !== null) {
                    $channelPrices[] = $priceEntry;
                }
            }
            $prices[$channelKey] = $channelPrices;

            $availabilityStatuses[$channelKey] = $availability;
            $stockQuantities[$channelKey] = $stock;
            $minOrderQuantities[$channelKey] = $minPurchase;
            $maxOrderQuantities[$channelKey] = $maxPurchase;

            $variantImages = $this->getImages($variant);
            $images[$channelKey] = !empty($variantImages) ? $variantImages : ($parentImages[$channelKey] ?? []);
        }

        $data = [
            'identification_number' => 'variation-' . $variantId,
            'sku' => $variant->getProductNumber(),
            'channels' => $channelKeys,
            'names' => $names,
            'links' => $links,
            'prices' => $prices,
            'availability_statuses' => $availabilityStatuses,
            'stock_quantities' => $stockQuantities,
            'images' => $images,
            'attributes' => $attributes,
            'parent_sku' => $parentSku,
            'min_order_quantities' => $minOrderQuantities,
            'max_order_quantities' => $maxOrderQuantities,
            'available_for_order' => true,
            'condition' => null,
            'is_virtual' => $this->isVirtual($variant),
        ];

        if ($syncSessionId !== null) {
            $data['sync_session_id'] = $syncSessionId;
        }

        return $data;
    }

    private function getProductUrl(ProductEntity $product, string $domainUrl, ?string $salesChannelId = null, ?string $languageId = null): string
    {
        $seoUrls = $product->getSeoUrls();
        if ($seoUrls === null || $seoUrls->count() === 0) {
            return rtrim($domainUrl, '/') . '/detail/' . $product->getId();
        }

        // 1. Canonical URL matching exact channel + language
        foreach ($seoUrls as $seoUrl) {
            if ($salesChannelId !== null && $seoUrl->getSalesChannelId() !== $salesChannelId) {
                continue;
            }
            if ($languageId !== null && $seoUrl->getLanguageId() !== $languageId) {
                continue;
            }
            if ($seoUrl->getIsCanonical()) {
                return rtrim($domainUrl, '/') . '/' . ltrim($seoUrl->getSeoPathInfo(), '/');
            }
        }

        // 2. Any URL matching exact channel + language
        foreach ($seoUrls as $seoUrl) {
            if ($salesChannelId !== null && $seoUrl->getSalesChannelId() !== $salesChannelId) {
                continue;
            }
            if ($languageId !== null && $seoUrl->getLanguageId() !== $languageId) {
                continue;
            }
            return rtrim($domainUrl, '/') . '/' . ltrim($seoUrl->getSeoPathInfo(), '/');
        }

        // 3. Any URL matching channel (any language), use path only
        foreach ($seoUrls as $seoUrl) {
            if ($salesChannelId !== null && $seoUrl->getSalesChannelId() !== $salesChannelId) {
                continue;
            }
            return rtrim($domainUrl, '/') . '/' . ltrim($seoUrl->getSeoPathInfo(), '/');
        }

        // 4. Fallback: generic product detail URL (never use SEO URL from wrong channel)
        return rtrim($domainUrl, '/') . '/detail/' . $product->getId();
    }

    /**
     * Build full hierarchical category paths using breadcrumbs, per-language.
     * Returns paths like "Electronics > TVs > OLED TVs".
     *
     * @return string[]
     */
    private function getCategoryHierarchy(ProductEntity $product, string $defaultCategory, string $languageId): array
    {
        $categories = $product->getCategories();
        if ($categories === null || $categories->count() === 0) {
            return $defaultCategory !== '' ? [$defaultCategory] : [];
        }

        $paths = [];
        foreach ($categories as $category) {
            $breadcrumb = null;

            // Try language-specific breadcrumb from translations
            if ($languageId !== '') {
                $translations = $category->getTranslations();
                if ($translations !== null) {
                    foreach ($translations as $translation) {
                        if ($translation->getLanguageId() === $languageId) {
                            $breadcrumb = $translation->getBreadcrumb();
                            break;
                        }
                    }
                }
            }

            // Fallback to default breadcrumb
            if ($breadcrumb === null) {
                $translatedBreadcrumb = $category->getTranslation('breadcrumb');
                $breadcrumb = \is_array($translatedBreadcrumb) ? $translatedBreadcrumb : $category->getBreadcrumb();
            }

            if ($breadcrumb !== []) {
                $parts = array_values($breadcrumb);
                // Skip root navigation category (first entry)
                if (\count($parts) > 1) {
                    array_shift($parts);
                }
                $paths[] = implode(' > ', $parts);
            } else {
                // Fallback: use category name directly
                $catName = $this->getTranslatedString($category, 'name', $languageId);
                if ($catName !== '') {
                    $paths[] = $catName;
                }
            }
        }

        $paths = array_values(array_unique($paths));

        return !empty($paths) ? $paths : ($defaultCategory !== '' ? [$defaultCategory] : []);
    }

    /**
     * Resolve brand per-language: property group attribute > manufacturer > default.
     */
    private function getBrand(ProductEntity $product, string $defaultBrand, string $languageId): string
    {
        $brandAttributeId = $this->config->getBrandAttribute();
        if ($brandAttributeId !== '') {
            $properties = $product->getProperties();
            if ($properties !== null) {
                foreach ($properties as $property) {
                    $group = $property->getGroup();
                    if ($group !== null && $group->getId() === $brandAttributeId) {
                        $valueName = $this->getTranslatedString($property, 'name', $languageId);
                        if ($valueName !== '') {
                            return $valueName;
                        }
                    }
                }
            }
        }

        $manufacturer = $product->getManufacturer();
        if ($manufacturer !== null) {
            $name = $this->getTranslatedString($manufacturer, 'name', $languageId);
            if ($name !== '') {
                return $name;
            }
        }

        return $defaultBrand;
    }

    /**
     * @return string[]
     */
    private function getImages(ProductEntity $product): array
    {
        $media = $product->getMedia();
        if ($media === null || $media->count() === 0) {
            $cover = $product->getCover();
            if ($cover !== null && $cover->getMedia() !== null && $cover->getMedia()->getUrl() !== '') {
                return [$cover->getMedia()->getUrl()];
            }

            return [];
        }

        $sorted = $media->getElements();
        usort($sorted, fn (ProductMediaEntity $a, ProductMediaEntity $b) => $a->getPosition() <=> $b->getPosition());

        $urls = [];
        foreach ($sorted as $productMedia) {
            $mediaEntity = $productMedia->getMedia();
            if ($mediaEntity !== null && $mediaEntity->getUrl() !== '') {
                $urls[] = $mediaEntity->getUrl();
            }
        }

        return $urls;
    }

    /**
     * @return array{currency: string, current_price: float, regular_price: float, price_incl_tax?: float, price_excl_tax?: float, tier_prices?: array<int, array{min_quantity: int, price: float}>}|null
     */
    private function buildPriceEntry(?PriceCollection $prices, ?ProductPriceCollection $rulePrices, string $currencyIso, ?string $currencyId = null, ?string $salesChannelId = null, float $currencyFactor = 1.0, ?CashRoundingConfig $rounding = null): ?array
    {
        $rounding ??= new CashRoundingConfig(2, 0.01, true);

        // When a guest's rule has advanced prices, the storefront prices the
        // product from them alone: the lowest quantity row is what one unit
        // costs (ProductCartProcessor::getPriceDefinition), and the product's
        // own price and list price are not shown.
        $guestRows = $this->guestRulePriceRows($rulePrices, $currencyId, $salesChannelId);
        $baseRow = array_shift($guestRows);
        $price = $baseRow['price'] ?? null;

        if ($price === null) {
            if ($prices === null || $prices->count() === 0) {
                return null;
            }
            if ($currencyId !== null) {
                $price = $prices->getCurrencyPrice($currencyId);
            }
            // Only fall back to default price when no specific currency was requested
            if ($price === null && $currencyId === null) {
                $price = $prices->first();
            }
            if ($price === null) {
                return null;
            }
        }

        $factor = $this->conversionFactor($price, $currencyId, $currencyFactor);
        $gross = $this->cashRounding->cashRound($price->getGross() * $factor, $rounding);
        $net = $this->cashRounding->mathRound($price->getNet() * $factor, $rounding);
        $listPrice = $price->getListPrice();
        $regularGross = $listPrice !== null ? $this->cashRounding->cashRound($listPrice->getGross() * $factor, $rounding) : $gross;

        // Headline is always the tax-included price (what a B2C shopper pays),
        // matching the other Emporiqa integrations. The incl./excl. pair is
        // always sent when tax applies (gross != net) so the store's
        // "Show tax info" dashboard toggle has the data to render the
        // breakdown. Display is decided in the dashboard, not here.
        $entry = [
            'currency' => $currencyIso,
            'current_price' => $gross,
            'regular_price' => $regularGross,
        ];

        if (abs($gross - $net) > 0.001) {
            $entry['price_incl_tax'] = $gross;
            $entry['price_excl_tax'] = $net;
        }

        $tierPrices = $this->buildTierPrices($guestRows, $currencyId, $gross, $currencyFactor, $rounding);
        if (!empty($tierPrices)) {
            $entry['tier_prices'] = $tierPrices;
        }

        return $entry;
    }

    /**
     * The advanced-price rows a guest shopper is priced with, for one currency,
     * in ascending quantity order.
     *
     * Only the highest-priority rule that a guest matches and that has prices on
     * this product counts, the same selection the storefront's price calculator
     * makes. Prices of rules scoped to a customer group or any other audience are
     * never published. A row without a price in this currency keeps its place
     * with a null price, so it can never be mistaken for the base row.
     *
     * @return list<array{quantity: int, price: ?Price}>
     */
    private function guestRulePriceRows(?ProductPriceCollection $rulePrices, ?string $currencyId, ?string $salesChannelId): array
    {
        if ($rulePrices === null || $rulePrices->count() === 0 || $salesChannelId === null || $salesChannelId === '') {
            return [];
        }

        $guestRulePrices = null;
        foreach ($this->guestRuleResolver->getGuestRuleIds($salesChannelId, $currencyId) as $ruleId) {
            $filtered = $rulePrices->filterByRuleId($ruleId);
            if ($filtered->count() > 0) {
                $guestRulePrices = $filtered;
                break;
            }
        }
        if ($guestRulePrices === null) {
            return [];
        }

        $rows = [];
        foreach ($guestRulePrices as $rulePrice) {
            $priceCollection = $rulePrice->getPrice();
            $rows[] = [
                'quantity' => $rulePrice->getQuantityStart(),
                'price' => $currencyId !== null ? $priceCollection->getCurrencyPrice($currencyId) : $priceCollection->first(),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['quantity'] <=> $b['quantity']);

        return $rows;
    }

    /**
     * Quantity-discount tiers from the guest's advanced-price rows above the base row.
     *
     * @param list<array{quantity: int, price: ?Price}> $rows
     * @return array<int, array{min_quantity: int, price: float}>
     */
    private function buildTierPrices(array $rows, ?string $currencyId, float $currentPrice, float $currencyFactor, CashRoundingConfig $rounding): array
    {
        $byQuantity = [];
        foreach ($rows as $row) {
            $minQuantity = $row['quantity'];
            $price = $row['price'];
            if ($minQuantity <= 1 || $price === null) {
                continue;
            }

            $value = $this->cashRounding->cashRound($price->getGross() * $this->conversionFactor($price, $currencyId, $currencyFactor), $rounding);

            // Dedupe by quantity, keeping the lowest price (a rule may repeat a start quantity)
            if (!isset($byQuantity[$minQuantity]) || $value < $byQuantity[$minQuantity]) {
                $byQuantity[$minQuantity] = $value;
            }
        }

        ksort($byQuantity);

        $tiers = [];
        foreach ($byQuantity as $minQuantity => $value) {
            // Drop no-op tiers that are not cheaper than the base price
            if ($value >= $currentPrice) {
                continue;
            }
            $tiers[] = ['min_quantity' => $minQuantity, 'price' => $value];
        }

        return $tiers;
    }

    /**
     * The storefront cash-rounds every unit price with the currency's item
     * rounding (decimals and interval, e.g. 0.05 for CHF).
     *
     * @param array<string, string> $ctx
     */
    private function itemRounding(array $ctx): CashRoundingConfig
    {
        $decimals = isset($ctx['currencyDecimals']) && is_numeric($ctx['currencyDecimals']) ? (int) $ctx['currencyDecimals'] : 2;
        $interval = isset($ctx['currencyInterval']) && is_numeric($ctx['currencyInterval']) ? (float) $ctx['currencyInterval'] : 0.01;
        if ($decimals < 0 || $interval <= 0.0) {
            return new CashRoundingConfig(2, 0.01, true);
        }

        return new CashRoundingConfig($decimals, $interval, true);
    }

    /**
     * A product without its own price in the requested currency falls back to
     * the default-currency price, which the storefront converts with the
     * currency's exchange factor (ProductPriceCalculator). Mirror that here.
     */
    private function conversionFactor(Price $price, ?string $currencyId, float $currencyFactor): float
    {
        if ($currencyId === null || $currencyId === '' || $price->getCurrencyId() === $currencyId || $currencyFactor <= 0.0) {
            return 1.0;
        }

        return $currencyFactor;
    }

    /**
     * Map stock + closeout to an Emporiqa availability status.
     */
    private function resolveAvailability(int $stock, bool $isCloseout): string
    {
        if ($stock > 0) {
            return 'available';
        }

        return $isCloseout ? 'out_of_stock' : 'backorder';
    }

    /**
     * Resolve closeout for a variant: raw null inherits the parent value.
     * Entity::get() reads the raw property since getIsCloseout() coerces null to false.
     */
    private function resolveIsCloseout(ProductEntity $variant, bool $parentIsCloseout): bool
    {
        $own = $variant->get('isCloseout');

        return $own !== null ? (bool) $own : $parentIsCloseout;
    }

    /**
     * Aggregate availability and stock over active children:
     * available if any child is available, else backorder if any child is backorder.
     *
     * @param iterable<ProductEntity> $children
     * @return array{0: string, 1: int}
     */
    private function aggregateChildAvailability(iterable $children, bool $parentIsCloseout): array
    {
        $totalStock = 0;
        $anyAvailable = false;
        $anyBackorder = false;

        foreach ($children as $child) {
            if (($child->getActive() ?? true) === false) {
                continue;
            }

            $childStock = $child->getAvailableStock() ?? $child->getStock();
            $totalStock += $childStock;

            $status = $this->resolveAvailability($childStock, $this->resolveIsCloseout($child, $parentIsCloseout));
            if ($status === 'available') {
                $anyAvailable = true;
            } elseif ($status === 'backorder') {
                $anyBackorder = true;
            }
        }

        $availability = $anyAvailable ? 'available' : ($anyBackorder ? 'backorder' : 'out_of_stock');

        return [$availability, $totalStock];
    }

    /**
     * A product is virtual when its states contain the download state.
     * Entity::get() reads the raw property to avoid the getStates() deprecation in 6.7+.
     */
    private function isVirtual(ProductEntity $product): bool
    {
        $states = $product->get('states');
        if (!\is_array($states)) {
            return false;
        }

        return \in_array(self::PRODUCT_STATE_DOWNLOAD, $states, true);
    }

    /**
     * @param string[] $channelKeys
     * @return array<string, mixed>
     */
    private function buildAvailabilityEntry(string $identificationNumber, string $sku, array $channelKeys, string $availability, int $stock): array
    {
        $statuses = [];
        $stocks = [];
        foreach ($channelKeys as $channelKey) {
            $statuses[$channelKey] = $availability;
            $stocks[$channelKey] = $stock;
        }

        return [
            'identification_number' => $identificationNumber,
            'sku' => $sku,
            'availability_statuses' => $statuses,
            'stock_quantities' => $stocks,
        ];
    }

    /**
     * Collect property attributes per-language, handling multi-value groups.
     * Multiple values for the same group are joined with ", ".
     */
    /**
     * @return array<string, string>
     */
    private function getPropertyAttributes(ProductEntity $product, string $languageId): array
    {
        $properties = $product->getProperties();
        if ($properties === null || $properties->count() === 0) {
            return [];
        }

        $grouped = [];
        foreach ($properties as $property) {
            $group = $property->getGroup();
            $groupName = $group !== null ? $this->getTranslatedString($group, 'name', $languageId) : '';
            $valueName = $this->getTranslatedString($property, 'name', $languageId);

            if ($groupName !== '' && $valueName !== '') {
                $grouped[$groupName][] = $valueName;
            }
        }

        $result = [];
        foreach ($grouped as $groupName => $values) {
            $result[$groupName] = implode(', ', array_unique($values));
        }

        return $result;
    }

    /**
     * Get option attributes for a variant, per-language.
     */
    /**
     * @return array<string, string>
     */
    private function getOptionAttributes(ProductEntity $variant, string $languageId): array
    {
        $options = $variant->getOptions();
        if ($options === null || $options->count() === 0) {
            return [];
        }

        $result = [];
        foreach ($options as $option) {
            $group = $option->getGroup();
            $groupName = $group !== null ? $this->getTranslatedString($group, 'name', $languageId) : '';
            $valueName = $this->getTranslatedString($option, 'name', $languageId);

            if ($groupName !== '' && $valueName !== '') {
                $result[$groupName] = $valueName;
            }
        }

        return $result;
    }

    /**
     * Keep only the channel contexts of sales channels whose storefront search
     * finds the product. Visibilities not loaded: no information, every
     * channel is kept. Loaded but empty, or "Hide in listings and search"
     * everywhere: no channel is kept.
     *
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, array<int, array<string, string>>>
     */
    private function filterByVisibility(ProductEntity $product, array $channelContexts): array
    {
        $visibilities = $product->getVisibilities();
        if ($visibilities === null) {
            return $channelContexts;
        }

        // "Hide in listings and search" (VISIBILITY_LINK) leaves the product
        // reachable only by its URL. The chat is a search, so it follows
        // Shopware's own product search: VISIBILITY_SEARCH and up.
        $visibleSalesChannelIds = [];
        foreach ($visibilities as $visibility) {
            if ($visibility->getVisibility() >= ProductVisibilityDefinition::VISIBILITY_SEARCH) {
                $visibleSalesChannelIds[$visibility->getSalesChannelId()] = true;
            }
        }

        $filtered = [];
        foreach ($channelContexts as $channelKey => $contexts) {
            // Contexts of sales channels the product is visible in come first, so
            // the per-language link points to a channel that actually serves it.
            $visible = [];
            $hidden = [];
            foreach ($contexts as $ctx) {
                if (isset($visibleSalesChannelIds[$ctx['salesChannelId'] ?? ''])) {
                    $visible[] = $ctx;
                } else {
                    $hidden[] = $ctx;
                }
            }
            if ($visible !== []) {
                $filtered[$channelKey] = array_merge($visible, $hidden);
            }
        }

        return $filtered;
    }

    /**
     * Collect variation attribute group names from ALL children to handle
     * heterogeneous variants (e.g., some have Color, others have Color + Size).
     *
     * @param iterable<ProductEntity> $children
     * @return string[]
     */
    private function getVariationAttributeNamesFromChildren(iterable $children, string $languageId = ''): array
    {
        $names = [];

        foreach ($children as $child) {
            $options = $child->getOptions();
            if ($options === null) {
                continue;
            }

            foreach ($options as $option) {
                $group = $option->getGroup();
                if ($group !== null) {
                    $groupName = $this->getTranslatedString($group, 'name', $languageId);
                    if ($groupName !== '' && !\in_array($groupName, $names, true)) {
                        $names[] = $groupName;
                    }
                }
            }
        }

        return $names;
    }
}
