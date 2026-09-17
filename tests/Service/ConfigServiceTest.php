<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\ConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigServiceTest extends TestCase
{
    private SystemConfigService&MockObject $systemConfig;
    private ConfigService $configService;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->configService = new ConfigService($this->systemConfig);
    }

    public function testGetStoreIdReturnsConfigValue(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.storeId', null)
            ->willReturn('my-store-123');

        $this->assertSame('my-store-123', $this->configService->getStoreId());
    }

    public function testGetStoreIdReturnsCastString(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.storeId', null)
            ->willReturn(null);

        $this->assertSame('', $this->configService->getStoreId());
    }

    public function testGetStoreIdWithSalesChannelId(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.storeId', 'channel-abc')
            ->willReturn('channel-store');

        $this->assertSame('channel-store', $this->configService->getStoreId('channel-abc'));
    }

    public function testGetWebhookUrlReturnsConfiguredUrl(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.webhookUrl', null)
            ->willReturn('https://custom.example.com/webhook/');

        $this->assertSame('https://custom.example.com/webhook/', $this->configService->getWebhookUrl());
    }

    public function testGetWebhookUrlReturnsDefaultWhenEmpty(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.webhookUrl', null)
            ->willReturn('');

        $this->assertSame('https://emporiqa.com/webhooks/sync/', $this->configService->getWebhookUrl());
    }

    public function testGetWebhookUrlReturnsDefaultWhenNull(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.webhookUrl', null)
            ->willReturn(null);

        $this->assertSame('https://emporiqa.com/webhooks/sync/', $this->configService->getWebhookUrl());
    }

    public function testGetWebhookSecret(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.webhookSecret', null)
            ->willReturn('secret-key-abc');

        $this->assertSame('secret-key-abc', $this->configService->getWebhookSecret());
    }

    public function testGetBatchSizeReturnsConfiguredValue(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.batchSize', null)
            ->willReturn(100);

        $this->assertSame(100, $this->configService->getBatchSize());
    }

    public function testGetBatchSizeReturnsDefaultWhenZero(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.batchSize', null)
            ->willReturn(0);

        $this->assertSame(50, $this->configService->getBatchSize());
    }

    public function testGetBatchSizeCapsAt200(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.batchSize', null)
            ->willReturn(500);

        $this->assertSame(200, $this->configService->getBatchSize());
    }

    public function testIsConfiguredReturnsTrueWhenBothSet(): void
    {
        $this->systemConfig
            ->method('get')
            ->willReturnMap([
                ['EmporiqaIntegration.config.storeId', null, 'store-123'],
                ['EmporiqaIntegration.config.webhookSecret', null, 'secret-abc'],
            ]);

        $this->assertTrue($this->configService->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenStoreIdEmpty(): void
    {
        $this->systemConfig
            ->method('get')
            ->willReturnMap([
                ['EmporiqaIntegration.config.storeId', null, ''],
                ['EmporiqaIntegration.config.webhookSecret', null, 'secret-abc'],
            ]);

        $this->assertFalse($this->configService->isConfigured());
    }

    public function testGetFullWebhookUrlConstructsUrlCorrectly(): void
    {
        $this->systemConfig
            ->method('get')
            ->willReturnMap([
                ['EmporiqaIntegration.config.webhookUrl', null, 'https://emporiqa.com/webhooks/sync/'],
                ['EmporiqaIntegration.config.storeId', null, 'store-xyz'],
            ]);

        $this->assertSame(
            'https://emporiqa.com/webhooks/sync/store-xyz/',
            $this->configService->getFullWebhookUrl()
        );
    }

    public function testIsCartEnabledAlwaysReturnsTrue(): void
    {
        $this->assertTrue($this->configService->isCartEnabled());
        $this->assertTrue($this->configService->isCartEnabled('any-channel'));
    }

    public function testIsOrderTrackingEnabledAlwaysReturnsTrue(): void
    {
        $this->assertTrue($this->configService->isOrderTrackingEnabled());
        $this->assertTrue($this->configService->isOrderTrackingEnabled('any-channel'));
    }

    public function testGetChannelMappingParsesJson(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.channelMapping', null)
            ->willReturn('{"channel-1":"b2b","channel-2":"retail"}');

        $result = $this->configService->getChannelMapping();

        $this->assertSame(['channel-1' => 'b2b', 'channel-2' => 'retail'], $result);
    }

    public function testGetChannelMappingReturnsEmptyOnEmptyString(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.channelMapping', null)
            ->willReturn('');

        $this->assertSame([], $this->configService->getChannelMapping());
    }

    public function testGetChannelMappingReturnsEmptyOnInvalidJson(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.channelMapping', null)
            ->willReturn('not-json');

        $this->assertSame([], $this->configService->getChannelMapping());
    }

    public function testGetOrderCompletedStatesReturnsDefaults(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.orderCompletedStates', null)
            ->willReturn('');

        $result = $this->configService->getOrderCompletedStates();

        $this->assertSame(['order.state.completed', 'order_transaction.state.paid'], $result);
    }

    public function testGetOrderCompletedStatesReturnsConfigured(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.orderCompletedStates', null)
            ->willReturn('["order.state.completed"]');

        $this->assertSame(['order.state.completed'], $this->configService->getOrderCompletedStates());
    }

    public function testIsOrderRequireEmailAlwaysReturnsTrue(): void
    {
        $this->assertTrue($this->configService->isOrderRequireEmail());
        $this->assertTrue($this->configService->isOrderRequireEmail('any-channel'));
    }


    public function testGetEnabledLanguagesReturnsEmptyWhenUnset(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.enabledLanguages', null)
            ->willReturn(null);

        $this->assertSame([], $this->configService->getEnabledLanguages());
    }

    public function testGetEnabledLanguagesReturnsConfiguredCodes(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.enabledLanguages', null)
            ->willReturn('["en-GB","",42,"de-DE"]');

        $this->assertSame(['en-GB', 'de-DE'], $this->configService->getEnabledLanguages());
    }

    public function testGetEnabledLanguagesAcceptsArrayValues(): void
    {
        // Written as a real array through the system-config API instead of a JSON string
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.enabledLanguages', null)
            ->willReturn(['de-DE']);

        $this->assertSame(['de-DE'], $this->configService->getEnabledLanguages());
    }

    public function testGetEnabledLanguagesReturnsEmptyOnInvalidJson(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.enabledLanguages', null)
            ->willReturn('not-json');

        $this->assertSame([], $this->configService->getEnabledLanguages());
    }

    public function testGetEnabledSalesChannelsReturnsConfiguredIds(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.enabledSalesChannels', null)
            ->willReturn('["sc-1","","sc-2"]');

        $this->assertSame(['sc-1', 'sc-2'], $this->configService->getEnabledSalesChannels());
    }

    public function testGetEnabledSalesChannelsReturnsEmptyWhenUnset(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.enabledSalesChannels', null)
            ->willReturn(null);

        $this->assertSame([], $this->configService->getEnabledSalesChannels());
    }

    public function testGetBrandAttributeReturnsConfigured(): void
    {
        $this->systemConfig
            ->method('get')
            ->with('EmporiqaIntegration.config.brandAttribute', null)
            ->willReturn('group-uuid-123');

        $this->assertSame('group-uuid-123', $this->configService->getBrandAttribute());
    }
}
