<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigService implements ConfigServiceInterface
{
    private const CONFIG_PREFIX = 'EmporiqaIntegration.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    public function getStoreId(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfig->get(self::CONFIG_PREFIX . 'storeId', $salesChannelId);
    }

    public function getWebhookUrl(?string $salesChannelId = null): string
    {
        $url = (string) $this->systemConfig->get(self::CONFIG_PREFIX . 'webhookUrl', $salesChannelId);

        return $url ?: 'https://emporiqa.com/webhooks/sync/';
    }

    public function getWebhookSecret(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfig->get(self::CONFIG_PREFIX . 'webhookSecret', $salesChannelId);
    }

    public function isSyncProductsEnabled(?string $salesChannelId = null): bool
    {
        return (bool) ($this->systemConfig->get(self::CONFIG_PREFIX . 'syncProducts', $salesChannelId) ?? true);
    }

    public function isSyncPagesEnabled(?string $salesChannelId = null): bool
    {
        return (bool) ($this->systemConfig->get(self::CONFIG_PREFIX . 'syncPages', $salesChannelId) ?? true);
    }

    public function getBatchSize(?string $salesChannelId = null): int
    {
        $size = (int) $this->systemConfig->get(self::CONFIG_PREFIX . 'batchSize', $salesChannelId);

        if ($size <= 0) {
            return 50;
        }

        return min($size, 200);
    }

    public function isConfigured(?string $salesChannelId = null): bool
    {
        return $this->getStoreId($salesChannelId) !== ''
            && $this->getWebhookSecret($salesChannelId) !== '';
    }

    public function getFullWebhookUrl(?string $salesChannelId = null): string
    {
        return rtrim($this->getWebhookUrl($salesChannelId), '/')
            . '/' . $this->getStoreId($salesChannelId) . '/';
    }

    public function isCartEnabled(?string $salesChannelId = null): bool
    {
        return true;
    }

    public function isOrderTrackingEnabled(?string $salesChannelId = null): bool
    {
        return true;
    }

    public function getChannelMapping(?string $salesChannelId = null): array
    {
        $json = (string) $this->systemConfig->get(self::CONFIG_PREFIX . 'channelMapping', $salesChannelId);

        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function getBrandAttribute(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfig->get(self::CONFIG_PREFIX . 'brandAttribute', $salesChannelId);
    }

    public function getEnabledLanguages(?string $salesChannelId = null): array
    {
        return $this->getStringList('enabledLanguages', $salesChannelId);
    }

    public function getEnabledSalesChannels(?string $salesChannelId = null): array
    {
        return $this->getStringList('enabledSalesChannels', $salesChannelId);
    }

    /**
     * A JSON list setting (also accepted as a real array when written through the system-config API).
     *
     * @return string[]
     */
    private function getStringList(string $key, ?string $salesChannelId): array
    {
        $raw = $this->systemConfig->get(self::CONFIG_PREFIX . $key, $salesChannelId);

        $decoded = \is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn ($value): bool => \is_string($value) && $value !== ''));
    }

    public function getOrderCompletedStates(?string $salesChannelId = null): array
    {
        $json = (string) $this->systemConfig->get(self::CONFIG_PREFIX . 'orderCompletedStates', $salesChannelId);

        if ($json === '') {
            return ['order.state.completed', 'order_transaction.state.paid'];
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) && !empty($decoded)
            ? $decoded
            : ['order.state.completed', 'order_transaction.state.paid'];
    }

    public function isOrderRequireEmail(?string $salesChannelId = null): bool
    {
        return true;
    }

}
