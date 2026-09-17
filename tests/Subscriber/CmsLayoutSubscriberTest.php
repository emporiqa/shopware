<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Subscriber\CmsLayoutSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class CmsLayoutSubscriberTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private Connection&MockObject $connection;
    private MessageBusInterface&MockObject $messageBus;
    private CmsLayoutSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);
        $this->connection = $this->createMock(Connection::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->subscriber = new CmsLayoutSubscriber(
            $this->config,
            $this->connection,
            $this->messageBus,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testSubscribesAfterEntityIndexing(): void
    {
        $this->assertSame(
            [EntityWrittenContainerEvent::class => ['onEntityWrittenContainer', -100]],
            CmsLayoutSubscriber::getSubscribedEvents(),
        );
    }

    public function testLayoutWriteQueuesResyncWithoutQuery(): void
    {
        $pageId = Uuid::randomHex();
        $this->connection->expects($this->never())->method('fetchFirstColumn');
        $this->expectResyncFor([$pageId]);

        $this->subscriber->onEntityWrittenContainer($this->containerEvent(['cms_page' => [$pageId]]));
    }

    public function testSlotTranslationWriteResolvesLayoutAndQueuesResync(): void
    {
        $slotId = Uuid::randomHex();
        $pageId = Uuid::randomHex();

        $this->connection->expects($this->once())
            ->method('fetchFirstColumn')
            ->with(
                $this->stringContains('FROM cms_slot slot'),
                ['ids' => [Uuid::fromHexToBytes($slotId)], 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            )
            ->willReturn([$pageId]);
        $this->expectResyncFor([$pageId]);

        $this->subscriber->onEntityWrittenContainer($this->containerEvent([
            'cms_slot_translation' => [['cmsSlotId' => $slotId, 'languageId' => Uuid::randomHex()]],
        ]));
    }

    public function testSectionAndBlockWritesAreResolvedAndDeduplicated(): void
    {
        $pageId = Uuid::randomHex();

        $this->connection->expects($this->exactly(2))
            ->method('fetchFirstColumn')
            ->willReturn([$pageId]);
        $this->expectResyncFor([$pageId]);

        $this->subscriber->onEntityWrittenContainer($this->containerEvent([
            'cms_section' => [Uuid::randomHex()],
            'cms_block' => [Uuid::randomHex()],
        ]));
    }

    public function testIgnoresWritesOfOtherEntities(): void
    {
        $this->connection->expects($this->never())->method('fetchFirstColumn');
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onEntityWrittenContainer($this->containerEvent(['product' => [Uuid::randomHex()]]));
    }

    public function testSkipsWhenPageSyncDisabled(): void
    {
        $config = $this->createMock(ConfigServiceInterface::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isSyncPagesEnabled')->willReturn(false);
        $this->messageBus->expects($this->never())->method('dispatch');

        $subscriber = new CmsLayoutSubscriber($config, $this->connection, $this->messageBus, $this->createMock(LoggerInterface::class));
        $subscriber->onEntityWrittenContainer($this->containerEvent(['cms_page' => [Uuid::randomHex()]]));
    }

    public function testSkipsNonLiveVersion(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');

        $context = Context::createDefaultContext()->createWithVersionId(Uuid::randomHex());
        $this->subscriber->onEntityWrittenContainer($this->containerEvent(['cms_page' => [Uuid::randomHex()]], $context));
    }

    /**
     * @param list<string> $pageIds
     */
    private function expectResyncFor(array $pageIds): void
    {
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message instanceof PageResyncMessage && $message->getCmsPageIds() === $pageIds))
            ->willReturn(new Envelope(new \stdClass()));
    }

    /**
     * @param array<string, list<string|array<string, string>>> $primaryKeysByEntity
     */
    private function containerEvent(array $primaryKeysByEntity, ?Context $context = null): EntityWrittenContainerEvent
    {
        $context ??= Context::createDefaultContext();

        $events = [];
        foreach ($primaryKeysByEntity as $entityName => $primaryKeys) {
            $results = [];
            foreach ($primaryKeys as $primaryKey) {
                $results[] = new EntityWriteResult($primaryKey, [], $entityName, EntityWriteResult::OPERATION_UPDATE);
            }
            $events[] = new EntityWrittenEvent($entityName, $results, $context);
        }

        return new EntityWrittenContainerEvent($context, new NestedEventCollection($events), []);
    }
}
