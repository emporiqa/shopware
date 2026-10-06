<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

interface ConfigServiceInterface
{
    public function getStoreId(?string $salesChannelId = null): string;

    public function getWebhookUrl(?string $salesChannelId = null): string;

    public function getWebhookSecret(?string $salesChannelId = null): string;

    public function isSyncProductsEnabled(?string $salesChannelId = null): bool;

    public function isSyncPagesEnabled(?string $salesChannelId = null): bool;

    public function getBatchSize(?string $salesChannelId = null): int;

    public function isConfigured(?string $salesChannelId = null): bool;

    public function getFullWebhookUrl(?string $salesChannelId = null): string;

    public function isCartEnabled(?string $salesChannelId = null): bool;

    public function isOrderTrackingEnabled(?string $salesChannelId = null): bool;

    /**
     * @return array<string, string> Map of Shopware sales channel ID => Emporiqa channel key
     */
    public function getChannelMapping(?string $salesChannelId = null): array;

    public function getBrandAttribute(?string $salesChannelId = null): string;

    /**
     * @return string[] Locale codes (e.g. en-GB) to include in synced data; empty means all languages
     */
    public function getEnabledLanguages(?string $salesChannelId = null): array;

    /**
     * @return string[] IDs of the sales channels to sync and show the widget on; empty means all storefront channels
     */
    public function getEnabledSalesChannels(?string $salesChannelId = null): array;

    /**
     * @return string[] Technical names of order/transaction states that trigger order.completed
     */
    public function getOrderCompletedStates(?string $salesChannelId = null): array;

    public function isOrderRequireEmail(?string $salesChannelId = null): bool;

    /**
     * Keep what Emporiqa said about ready-made rules (connect exchange or the
     * Test connection dry run): `rules_available` and `live_rules`.
     *
     * @param array<string, mixed> $answer
     */
    public function saveRulesStatus(array $answer): void;

    public function isRulesAvailable(): bool;

    /**
     * @return string[] ready-made rule keys Emporiqa last reported live
     */
    public function getLiveRules(): array;
}
