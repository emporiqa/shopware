<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\FullSyncMessage;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Emporiqa\ShopwarePlugin\Subscriber\UpgradeResyncSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class UpgradeResyncSubscriberTest extends TestCase
{
    private SystemConfigService&MockObject $systemConfig;
    private ConfigServiceInterface&MockObject $config;
    private MessageBusInterface&MockObject $messageBus;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
    }

    public function testSubscribesToStorefrontRender(): void
    {
        $this->assertArrayHasKey(StorefrontRenderEvent::class, UpgradeResyncSubscriber::getSubscribedEvents());
    }

    public function testNothingHappensWithoutPendingFlag(): void
    {
        $this->systemConfig->method('getBool')->willReturn(false);
        $this->systemConfig->expects($this->never())->method('delete');
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber()->onStorefrontRender($this->createMock(StorefrontRenderEvent::class));
    }

    public function testPendingFlagQueuesOneProductSyncAndIsCleared(): void
    {
        $this->systemConfig->method('getBool')->with(UpgradeResyncSubscriber::PENDING_RESYNC_KEY)->willReturn(true);
        $this->systemConfig->expects($this->once())->method('delete')->with(UpgradeResyncSubscriber::PENDING_RESYNC_KEY);
        $this->config->method('isConfigured')->willReturn(true);
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($m) => $m instanceof FullSyncMessage && $m->getEntity() === 'products'))
            ->willReturnCallback(fn ($m) => new Envelope($m));

        $this->subscriber()->onStorefrontRender($this->createMock(StorefrontRenderEvent::class));
    }

    public function testUnconfiguredPluginClearsFlagWithoutSyncing(): void
    {
        $this->systemConfig->method('getBool')->willReturn(true);
        $this->systemConfig->expects($this->once())->method('delete');
        $this->config->method('isConfigured')->willReturn(false);
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber()->onStorefrontRender($this->createMock(StorefrontRenderEvent::class));
    }

    public function testQueueFailureNeverBreaksThePage(): void
    {
        $this->systemConfig->method('getBool')->willReturn(true);
        $this->config->method('isConfigured')->willReturn(true);
        $this->messageBus->method('dispatch')->willThrowException(new \RuntimeException('transport down'));

        $this->subscriber()->onStorefrontRender($this->createMock(StorefrontRenderEvent::class));

        $this->addToAssertionCount(1);
    }

    /**
     * S3 on an update: the empty home page an older version sent is checked
     * once, which sends its delete (PageResyncMessageHandler::syncCategory).
     */
    public function testPendingHomePageCheckQueuesTheNavigationRootsOnceAndIsCleared(): void
    {
        $this->systemConfig->method('getBool')->willReturnCallback(fn (string $key) => $key === UpgradeResyncSubscriber::PENDING_HOME_PAGE_CHECK_KEY);
        $this->systemConfig->expects($this->once())->method('delete')->with(UpgradeResyncSubscriber::PENDING_HOME_PAGE_CHECK_KEY);
        $this->config->method('isConfigured')->willReturn(true);
        $sync = $this->createMock(SyncServiceInterface::class);
        $sync->method('buildChannelContexts')->willReturn([
            'storefront' => [['navigationCategoryId' => 'root-1'], ['navigationCategoryId' => 'root-1']],
            'b2b' => [['navigationCategoryId' => 'root-2']],
        ]);
        $this->messageBus->expects($this->once())->method('dispatch')
            ->with($this->callback(fn ($m) => $m instanceof PageResyncMessage && $m->getCategoryIds() === ['root-1', 'root-2'] && $m->getCreatedIds() === []))
            ->willReturnCallback(fn ($m) => new Envelope($m));

        (new UpgradeResyncSubscriber($this->systemConfig, $this->config, $this->messageBus, new NullLogger(), $sync))
            ->onStorefrontRender($this->createMock(StorefrontRenderEvent::class));
    }

    public function testHomePageCheckOnAnUnconfiguredPluginOnlyClearsTheFlag(): void
    {
        $this->systemConfig->method('getBool')->willReturnCallback(fn (string $key) => $key === UpgradeResyncSubscriber::PENDING_HOME_PAGE_CHECK_KEY);
        $this->systemConfig->expects($this->once())->method('delete');
        $this->config->method('isConfigured')->willReturn(false);
        $this->messageBus->expects($this->never())->method('dispatch');

        (new UpgradeResyncSubscriber($this->systemConfig, $this->config, $this->messageBus, new NullLogger(), $this->createMock(SyncServiceInterface::class)))
            ->onStorefrontRender($this->createMock(StorefrontRenderEvent::class));
    }

    private function subscriber(): UpgradeResyncSubscriber
    {
        return new UpgradeResyncSubscriber($this->systemConfig, $this->config, $this->messageBus, new NullLogger());
    }
}
