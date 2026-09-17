<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\PageSyncRegistry;
use Emporiqa\ShopwarePlugin\Subscriber\LandingPageSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\LandingPage\LandingPageEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class LandingPageSubscriberTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private CmsPageFormatterInterface&MockObject $cmsPageFormatter;
    private PageSyncRegistry $registry;
    private MessageBusInterface&MockObject $messageBus;
    private LandingPageSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->cmsPageFormatter = $this->createMock(CmsPageFormatterInterface::class);
        $this->registry = new PageSyncRegistry();
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->subscriber = new LandingPageSubscriber(
            $this->config,
            $this->cmsPageFormatter,
            $this->registry,
            $this->messageBus,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = LandingPageSubscriber::getSubscribedEvents();

        // Runs after entity indexing (priority 1000) so SEO URLs are up to date.
        $this->assertSame(['onEntityWrittenContainer', -100], $events[EntityWrittenContainerEvent::class]);
        $this->assertSame('onLandingPageDeleted', $events[LandingPageEvents::LANDING_PAGE_DELETED_EVENT]);
        $this->assertArrayNotHasKey(LandingPageEvents::LANDING_PAGE_WRITTEN_EVENT, $events);
    }

    public function testWrittenSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onLandingPageWritten($this->writtenEvent(['page-1']));
    }

    public function testWrittenSkipsWhenSyncDisabled(): void
    {
        $this->configureEnabled(false);
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onLandingPageWritten($this->writtenEvent(['page-1']));
    }

    public function testWrittenSkipsNonLiveVersion(): void
    {
        $this->configureEnabled();
        $this->messageBus->expects($this->never())->method('dispatch');

        $context = Context::createDefaultContext()->createWithVersionId(Uuid::randomHex());
        $this->subscriber->onLandingPageWritten($this->writtenEvent(['page-1'], context: $context));
    }

    public function testWrittenQueuesPageSyncWithCreatedIds(): void
    {
        $this->configureEnabled();
        $this->expectPageSync(landingPageIds: ['page-1', 'page-2'], createdIds: ['page-2']);

        $this->subscriber->onLandingPageWritten($this->writtenEvent(['page-1', 'page-2'], inserted: ['page-2']));
    }

    public function testCreatedIdsAreQueuedEvenWhenAlreadyClaimed(): void
    {
        $this->configureEnabled();
        $this->registry->claim(['page-old', 'page-new']);
        $this->expectPageSync(landingPageIds: ['page-new'], createdIds: ['page-new']);

        $this->subscriber->onLandingPageWritten($this->writtenEvent(['page-old', 'page-new'], inserted: ['page-new']));
    }

    public function testWrittenDeduplicatesPageIdsWithinRequest(): void
    {
        $this->configureEnabled();
        $this->messageBus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onLandingPageWritten($this->writtenEvent(['page-1']));
        $this->subscriber->onLandingPageWritten($this->writtenEvent(['page-1']));
    }

    public function testWrittenSkipsIdsAlreadyClaimedByAnotherSubscriber(): void
    {
        $this->configureEnabled();
        $this->registry->claim(['page-1']);
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onLandingPageWritten($this->writtenEvent(['page-1']));
    }

    public function testContainerEventHandlesLandingPageWritesOnly(): void
    {
        $this->configureEnabled();
        $this->expectPageSync(landingPageIds: ['page-1'], createdIds: []);

        $context = Context::createDefaultContext();
        $this->subscriber->onEntityWrittenContainer(new EntityWrittenContainerEvent(
            $context,
            new NestedEventCollection([
                new EntityWrittenEvent('product', [new EntityWriteResult('prod-1', [], 'product', EntityWriteResult::OPERATION_UPDATE)], $context),
                $this->writtenEvent(['page-1']),
            ]),
            [],
        ));
    }

    public function testContainerEventIgnoresDeleteEvents(): void
    {
        $this->configureEnabled();
        $this->messageBus->expects($this->never())->method('dispatch');

        $context = Context::createDefaultContext();
        $deleted = new EntityDeletedEvent('landing_page', [new EntityWriteResult('page-1', [], 'landing_page', EntityWriteResult::OPERATION_DELETE)], $context);
        $this->subscriber->onEntityWrittenContainer(new EntityWrittenContainerEvent($context, new NestedEventCollection([$deleted]), []));
    }

    public function testDeletedDispatchesPageDeletedWebhook(): void
    {
        $this->configureEnabled();
        $this->cmsPageFormatter->method('formatPageDelete')->willReturn([['identification_number' => 'page-page-1']]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message instanceof WebhookMessage
                && $message->getEvents() === [['type' => 'page.deleted', 'data' => ['identification_number' => 'page-page-1']]]))
            ->willReturn(new Envelope(new \stdClass()));

        $context = Context::createDefaultContext();
        $this->subscriber->onLandingPageDeleted(new EntityDeletedEvent(
            'landing_page',
            [new EntityWriteResult('page-1', [], 'landing_page', EntityWriteResult::OPERATION_DELETE)],
            $context,
        ));
    }

    public function testDeletedSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->messageBus->expects($this->never())->method('dispatch');

        $context = Context::createDefaultContext();
        $this->subscriber->onLandingPageDeleted(new EntityDeletedEvent(
            'landing_page',
            [new EntityWriteResult('page-1', [], 'landing_page', EntityWriteResult::OPERATION_DELETE)],
            $context,
        ));
    }

    private function configureEnabled(bool $syncPages = true): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn($syncPages);
    }

    /**
     * @param list<string> $landingPageIds
     * @param list<string> $createdIds
     */
    private function expectPageSync(array $landingPageIds, array $createdIds): void
    {
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message instanceof PageResyncMessage
                && $message->getLandingPageIds() === $landingPageIds
                && $message->getCreatedIds() === $createdIds
                && $message->getCategoryIds() === []
                && $message->getCmsPageIds() === []))
            ->willReturn(new Envelope(new \stdClass()));
    }

    /**
     * @param list<string> $ids
     * @param list<string> $inserted
     */
    private function writtenEvent(array $ids, array $inserted = [], ?Context $context = null): EntityWrittenEvent
    {
        $results = [];
        foreach ($ids as $id) {
            $operation = \in_array($id, $inserted, true) ? EntityWriteResult::OPERATION_INSERT : EntityWriteResult::OPERATION_UPDATE;
            $results[] = new EntityWriteResult($id, ['name' => 'x'], 'landing_page', $operation);
        }

        return new EntityWrittenEvent('landing_page', $results, $context ?? Context::createDefaultContext());
    }
}
