<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\PageSyncRegistry;
use Emporiqa\ShopwarePlugin\Subscriber\CategorySubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class CategorySubscriberTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private CmsPageFormatterInterface&MockObject $cmsPageFormatter;
    private PageSyncRegistry $registry;
    private MessageBusInterface&MockObject $messageBus;
    private CategorySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->cmsPageFormatter = $this->createMock(CmsPageFormatterInterface::class);
        $this->registry = new PageSyncRegistry();
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->subscriber = new CategorySubscriber(
            $this->config,
            $this->cmsPageFormatter,
            $this->registry,
            $this->messageBus,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = CategorySubscriber::getSubscribedEvents();

        $this->assertSame(['onEntityWrittenContainer', -100], $events[EntityWrittenContainerEvent::class]);
        $this->assertSame('onCategoryDeleted', $events[CategoryEvents::CATEGORY_DELETED_EVENT]);
        $this->assertArrayNotHasKey(CategoryEvents::CATEGORY_WRITTEN_EVENT, $events);
    }

    public function testWrittenSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onCategoryWritten($this->writtenEvent([['cat-1', ['name' => 'x']]]));
    }

    public function testWrittenSkipsWhenSyncDisabled(): void
    {
        $this->configureEnabled(false);
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onCategoryWritten($this->writtenEvent([['cat-1', ['name' => 'x']]]));
    }

    public function testWrittenSkipsNonLiveVersion(): void
    {
        $this->configureEnabled();
        $this->messageBus->expects($this->never())->method('dispatch');

        $context = Context::createDefaultContext()->createWithVersionId(Uuid::randomHex());
        $this->subscriber->onCategoryWritten($this->writtenEvent([['cat-1', ['name' => 'x']]], context: $context));
    }

    public function testWrittenQueuesPageSyncWithCreatedAndStructuralIds(): void
    {
        $this->configureEnabled();

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message instanceof PageResyncMessage
                && $message->getCategoryIds() === ['cat-1', 'cat-2', 'cat-3']
                && $message->getCreatedIds() === ['cat-2']
                && $message->getStructuralCategoryIds() === ['cat-2', 'cat-3']
                && $message->getLandingPageIds() === []))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onCategoryWritten($this->writtenEvent([
            ['cat-1', ['name' => 'Renamed']],
            ['cat-2', ['name' => 'New', 'type' => 'page'], EntityWriteResult::OPERATION_INSERT],
            ['cat-3', ['cmsPageId' => 'layout-2']],
        ]));
    }

    public function testEmptyPayloadParentResultIsQueuedWithoutStructuralFlag(): void
    {
        // Product-to-category assignments produce empty-payload category results.
        $this->configureEnabled();

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message instanceof PageResyncMessage
                && $message->getCategoryIds() === ['cat-1']
                && $message->getStructuralCategoryIds() === []))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onCategoryWritten($this->writtenEvent([['cat-1', []]]));
    }

    public function testStructuralAndCreatedIdsAreQueuedEvenWhenAlreadyClaimed(): void
    {
        // The SEO URL listener fires before this subscriber and claims the ids; the
        // structural/created flags only exist here, so those ids must still be sent.
        $this->configureEnabled();
        $this->registry->claim(['cat-renamed', 'cat-layout', 'cat-new']);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message instanceof PageResyncMessage
                && $message->getCategoryIds() === ['cat-new', 'cat-layout']
                && $message->getCreatedIds() === ['cat-new']
                && $message->getStructuralCategoryIds() === ['cat-layout']))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onCategoryWritten($this->writtenEvent([
            ['cat-renamed', ['name' => 'Renamed']],
            ['cat-layout', ['cmsPageId' => 'listing-layout']],
            ['cat-new', ['name' => 'New'], EntityWriteResult::OPERATION_INSERT],
        ]));
    }

    public function testWrittenDeduplicatesWithinRequest(): void
    {
        $this->configureEnabled();
        $this->messageBus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onCategoryWritten($this->writtenEvent([['cat-1', []]]));
        $this->subscriber->onCategoryWritten($this->writtenEvent([['cat-1', []]]));
    }

    public function testContainerEventIgnoresDeleteEventsAndOtherEntities(): void
    {
        $this->configureEnabled();
        $this->messageBus->expects($this->never())->method('dispatch');

        $context = Context::createDefaultContext();
        $this->subscriber->onEntityWrittenContainer(new EntityWrittenContainerEvent($context, new NestedEventCollection([
            new EntityDeletedEvent('category', [new EntityWriteResult('cat-1', [], 'category', EntityWriteResult::OPERATION_DELETE)], $context),
            new EntityWrittenEvent('product', [new EntityWriteResult('prod-1', [], 'product', EntityWriteResult::OPERATION_UPDATE)], $context),
        ]), []));
    }

    public function testContainerEventHandlesCategoryWrites(): void
    {
        $this->configureEnabled();
        $this->messageBus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $context = Context::createDefaultContext();
        $this->subscriber->onEntityWrittenContainer(new EntityWrittenContainerEvent(
            $context,
            new NestedEventCollection([$this->writtenEvent([['cat-1', ['name' => 'x']]], $context)]),
            [],
        ));
    }

    public function testDeletedDispatchesPageDeletedWebhook(): void
    {
        $this->configureEnabled();
        $this->cmsPageFormatter->method('formatPageDelete')->willReturn([['identification_number' => 'page-cat-1']]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message instanceof WebhookMessage
                && $message->getEvents()[0]['type'] === 'page.deleted'
                && $message->getEvents()[0]['data']['identification_number'] === 'page-cat-1'))
            ->willReturn(new Envelope(new \stdClass()));

        $context = Context::createDefaultContext();
        $this->subscriber->onCategoryDeleted(new EntityDeletedEvent(
            'category',
            [new EntityWriteResult('cat-1', [], 'category', EntityWriteResult::OPERATION_DELETE)],
            $context,
        ));
    }

    private function configureEnabled(bool $syncPages = true): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn($syncPages);
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>, 2?: string}> $results id, payload, operation
     */
    private function writtenEvent(array $results, ?Context $context = null): EntityWrittenEvent
    {
        $writeResults = [];
        foreach ($results as $result) {
            $writeResults[] = new EntityWriteResult($result[0], $result[1], 'category', $result[2] ?? EntityWriteResult::OPERATION_UPDATE);
        }

        return new EntityWrittenEvent('category', $writeResults, $context ?? Context::createDefaultContext());
    }
}
