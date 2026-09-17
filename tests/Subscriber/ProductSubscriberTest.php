<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\ProductFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Emporiqa\ShopwarePlugin\Subscriber\ProductSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use Emporiqa\ShopwarePlugin\Tests\Support\EntityCollectionHelper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ProductSubscriberTest extends TestCase
{
    use EntityCollectionHelper;

    private ConfigServiceInterface&MockObject $config;
    private ProductFormatterInterface&MockObject $productFormatter;
    private SyncServiceInterface&MockObject $syncService;
    private EntityRepository&MockObject $productRepository;
    private MessageBusInterface&MockObject $messageBus;
    private LoggerInterface&MockObject $logger;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private Connection&MockObject $connection;
    private ProductSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->productFormatter = $this->createMock(ProductFormatterInterface::class);
        $this->syncService = $this->createMock(SyncServiceInterface::class);
        $this->productRepository = $this->createMock(EntityRepository::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->connection = $this->createMock(Connection::class);

        $this->subscriber = new ProductSubscriber(
            $this->config,
            $this->productFormatter,
            $this->syncService,
            $this->productRepository,
            $this->messageBus,
            $this->logger,
            $this->eventDispatcher,
            $this->connection,
        );
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = ProductSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(ProductEvents::PRODUCT_WRITTEN_EVENT, $events);
        $this->assertArrayHasKey(ProductEvents::PRODUCT_DELETED_EVENT, $events);
        $this->assertSame('onProductWritten', $events[ProductEvents::PRODUCT_WRITTEN_EVENT]);
        $this->assertSame('onProductDeleted', $events[ProductEvents::PRODUCT_DELETED_EVENT]);
        $this->assertSame('onEntityDelete', $events[EntityDeleteEvent::class]);
        $this->assertSame('onProductAssociationWritten', $events['product_media.written']);
        $this->assertSame('onProductAssociationDeleted', $events['product_media.deleted']);
        $this->assertSame('onProductAssociationWritten', $events['product_price.written']);
        $this->assertSame('onProductAssociationDeleted', $events['product_price.deleted']);
        $this->assertSame('onStockAltered', $events[ProductStockAlteredEvent::class]);
    }

    public function testOnProductWrittenSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->config->method('isSyncProductsEnabled')->willReturn(true);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onProductWritten($event);
    }

    public function testOnProductWrittenSkipsWhenSyncDisabled(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncProductsEnabled')->willReturn(false);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onProductWritten($event);
    }

    public function testOnProductWrittenSkipsNonLiveVersion(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncProductsEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn('non-live-version-id');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onProductWritten($event);
    }

    public function testOnProductWrittenDispatchesWebhookMessage(): void
    {
        $this->configureEnabled();

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('product-123');
        $writeResult->method('getOperation')->willReturn(EntityWriteResult::OPERATION_INSERT);
        $writeResult->method('getPayload')->willReturn(['parentId' => null]);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($this->createLiveContext());
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $this->mockLoadedProduct(active: true);

        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-123', 'names' => ['' => ['en' => 'Test']]],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof WebhookMessage
                    && count($message->getEvents()) === 1
                    && $message->getEvents()[0]['type'] === 'product.created';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onProductWritten($event);
    }

    public function testOnProductWrittenSendsDeleteWhenInactive(): void
    {
        $this->configureEnabled();

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('product-inactive');
        $writeResult->method('getPayload')->willReturn(['parentId' => null]);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($this->createLiveContext());
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $this->mockLoadedProduct(active: false);

        $this->productFormatter->method('formatProductDelete')->willReturn([
            ['identification_number' => 'product-product-inactive'],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof WebhookMessage
                    && count($message->getEvents()) === 1
                    && $message->getEvents()[0]['type'] === 'product.deleted';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onProductWritten($event);
    }

    // --- Variant deactivation / parent event-type redirect ---

    public function testVariantDeactivationDispatchesDeleteAndQueuesParentUpdate(): void
    {
        $this->configureEnabled();
        $variantId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $event = $this->createWrittenEvent([
            $this->createUpdateResult($variantId, ['parentId' => $parentId, 'active' => false]),
        ]);

        $parent = $this->createMock(ProductEntity::class);
        $parent->method('getActive')->willReturn(true);
        $parent->method('getParentId')->willReturn(null);
        $this->mockRepositorySearchReturns($parent);

        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-' . $parentId],
        ]);
        $this->productFormatter->expects($this->never())->method('formatProductDelete');

        $dispatched = [];
        $this->messageBus
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($message) use (&$dispatched) {
                $dispatched[] = $message;

                return new Envelope(new \stdClass());
            });

        $this->subscriber->onProductWritten($event);

        $this->assertSame('product.deleted', $dispatched[0]->getEvents()[0]['type']);
        $this->assertSame(
            ['identification_number' => 'variation-' . $variantId],
            $dispatched[0]->getEvents()[0]['data'],
        );
        $this->assertSame('product.updated', $dispatched[1]->getEvents()[0]['type']);
    }

    public function testChildInsertRedirectedToParentAlwaysUsesUpdatedType(): void
    {
        $this->configureEnabled();
        $variantId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn($variantId);
        $writeResult->method('getOperation')->willReturn(EntityWriteResult::OPERATION_INSERT);
        $writeResult->method('getPayload')->willReturn(['parentId' => $parentId]);

        $event = $this->createWrittenEvent([$writeResult]);

        $parent = $this->createMock(ProductEntity::class);
        $parent->method('getActive')->willReturn(true);
        $parent->method('getParentId')->willReturn(null);
        $this->mockRepositorySearchReturns($parent);

        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-' . $parentId],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message->getEvents()[0]['type'] === 'product.updated'))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onProductWritten($event);
    }

    // --- Stock-only writes / product.availability ---

    public function testStockOnlyWriteDispatchesAvailabilityEvent(): void
    {
        $this->configureEnabled();
        $productId = Uuid::randomHex();

        $event = $this->createWrittenEvent([
            $this->createUpdateResult($productId, [
                'id' => $productId,
                'versionId' => Defaults::LIVE_VERSION,
                'updatedAt' => '2026-07-10',
                'stock' => 5,
                'availableStock' => 5,
            ]),
        ]);

        $this->mockLoadedProduct(active: true);

        $entry = ['identification_number' => 'product-' . $productId, 'quantity' => 5];
        $this->productFormatter
            ->expects($this->once())
            ->method('formatAvailabilityEvents')
            ->willReturn([$entry]);
        $this->productFormatter->expects($this->never())->method('formatProduct');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) use ($entry) {
                return $message instanceof WebhookMessage
                    && count($message->getEvents()) === 1
                    && $message->getEvents()[0]['type'] === 'product.availability'
                    && $message->getEvents()[0]['data'] === $entry;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onProductWritten($event);
    }

    public function testStockOnlyWriteOnVariantPassesVariantToFormatter(): void
    {
        $this->configureEnabled();
        $variantId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $event = $this->createWrittenEvent([
            $this->createUpdateResult($variantId, ['id' => $variantId, 'stock' => 3]),
        ]);

        $variant = $this->createMock(ProductEntity::class);
        $variant->method('getParentId')->willReturn($parentId);
        $variant->method('getActive')->willReturn(null);
        $this->mockRepositorySearchReturns($variant);

        $this->productFormatter
            ->expects($this->once())
            ->method('formatAvailabilityEvents')
            ->with($this->identicalTo($variant))
            ->willReturn([['identification_number' => 'variation-' . $variantId, 'quantity' => 3]]);
        $this->productFormatter->expects($this->never())->method('formatProduct');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) use ($variantId) {
                return $message instanceof WebhookMessage
                    && $message->getEvents()[0]['type'] === 'product.availability'
                    && $message->getEvents()[0]['data']['identification_number'] === 'variation-' . $variantId;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onProductWritten($event);
    }

    public function testStockOnlyWriteDispatchesOneEventPerFormatterEntry(): void
    {
        $this->configureEnabled();
        $productId = Uuid::randomHex();

        $event = $this->createWrittenEvent([
            $this->createUpdateResult($productId, ['stock' => 1]),
        ]);

        $this->mockLoadedProduct(active: true);

        $this->productFormatter->method('formatAvailabilityEvents')->willReturn([
            ['identification_number' => 'product-' . $productId],
            ['identification_number' => 'variation-child-1'],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                $events = $message->getEvents();

                return \count($events) === 2
                    && $events[0]['type'] === 'product.availability'
                    && $events[1]['type'] === 'product.availability';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onProductWritten($event);
    }

    public function testFullUpdateSupersedesAvailabilityForSameProduct(): void
    {
        $this->configureEnabled();
        $productId = Uuid::randomHex();

        $event = $this->createWrittenEvent([
            $this->createUpdateResult($productId, ['name' => 'New name', 'stock' => 2]),
            $this->createUpdateResult($productId, ['stock' => 2]),
        ]);

        $this->mockLoadedProduct(active: true);
        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-' . $productId],
        ]);
        $this->productFormatter->expects($this->never())->method('formatAvailabilityEvents');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message->getEvents()[0]['type'] === 'product.updated'))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onProductWritten($event);
    }

    public function testDeleteSupersedesAvailabilityForSameProduct(): void
    {
        $this->configureEnabled();
        $productId = Uuid::randomHex();

        $this->productFormatter->method('formatProductDelete')->willReturn([
            ['identification_number' => 'product-' . $productId],
        ]);
        $this->productFormatter->expects($this->never())->method('formatAvailabilityEvents');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $deleteResult = $this->createMock(EntityWriteResult::class);
        $deleteResult->method('getPrimaryKey')->willReturn($productId);

        $deletedEvent = $this->createMock(EntityDeletedEvent::class);
        $deletedEvent->method('getContext')->willReturn($this->createLiveContext());
        $deletedEvent->method('getWriteResults')->willReturn([$deleteResult]);

        $this->subscriber->onProductDeleted($deletedEvent);

        $writtenEvent = $this->createWrittenEvent([
            $this->createUpdateResult($productId, ['stock' => 0]),
        ]);

        $this->subscriber->onProductWritten($writtenEvent);
    }

    public function testOnStockAlteredDispatchesAvailabilityEvents(): void
    {
        $this->configureEnabled();
        $productId = Uuid::randomHex();

        $this->mockLoadedProduct(active: true);

        $this->productFormatter->method('formatAvailabilityEvents')->willReturn([
            ['identification_number' => 'product-' . $productId, 'quantity' => 7],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message->getEvents()[0]['type'] === 'product.availability'))
            ->willReturn(new Envelope(new \stdClass()));

        $event = new ProductStockAlteredEvent([$productId], Context::createDefaultContext());
        $this->subscriber->onStockAltered($event);
    }

    public function testOnStockAlteredSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);

        $this->messageBus->expects($this->never())->method('dispatch');

        $event = new ProductStockAlteredEvent([Uuid::randomHex()], Context::createDefaultContext());
        $this->subscriber->onStockAltered($event);
    }

    // --- Variant deletes / parent re-queue ---

    public function testVariantDeleteSendsVariationPrefixAndRequeuesParent(): void
    {
        $this->configureEnabled();
        $variantId = Uuid::randomHex();
        $parentId = Uuid::randomHex();
        $context = $this->createLiveContext();

        $deleteEvent = $this->createMock(EntityDeleteEvent::class);
        $deleteEvent->method('getContext')->willReturn($context);
        $deleteEvent->method('getIds')->willReturnCallback(
            fn (string $entity) => $entity === 'product' ? [$variantId] : [],
        );

        $this->connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['id' => $variantId, 'parent_id' => $parentId]],
            [],
        );

        $this->subscriber->onEntityDelete($deleteEvent);

        $parent = $this->createMock(ProductEntity::class);
        $parent->method('getActive')->willReturn(true);
        $this->mockRepositorySearchReturns($parent);

        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-' . $parentId],
        ]);
        $this->productFormatter->expects($this->never())->method('formatProductDelete');

        $dispatched = [];
        $this->messageBus
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($message) use (&$dispatched) {
                $dispatched[] = $message;

                return new Envelope(new \stdClass());
            });

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn($variantId);

        $deletedEvent = $this->createMock(EntityDeletedEvent::class);
        $deletedEvent->method('getContext')->willReturn($context);
        $deletedEvent->method('getWriteResults')->willReturn([$writeResult]);

        $this->subscriber->onProductDeleted($deletedEvent);

        $this->assertSame('product.deleted', $dispatched[0]->getEvents()[0]['type']);
        $this->assertSame(
            ['identification_number' => 'variation-' . $variantId],
            $dispatched[0]->getEvents()[0]['data'],
        );
        $this->assertSame('product.updated', $dispatched[1]->getEvents()[0]['type']);
    }

    public function testParentDeleteSendsProductPrefixAndChildVariationDeletes(): void
    {
        $this->configureEnabled();
        $parentId = Uuid::randomHex();
        $childId = Uuid::randomHex();
        $context = $this->createLiveContext();

        $deleteEvent = $this->createMock(EntityDeleteEvent::class);
        $deleteEvent->method('getContext')->willReturn($context);
        $deleteEvent->method('getIds')->willReturnCallback(
            fn (string $entity) => $entity === 'product' ? [$parentId] : [],
        );

        $this->connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['id' => $parentId, 'parent_id' => null]],
            [['id' => $childId, 'parent_id' => $parentId]],
        );

        $this->subscriber->onEntityDelete($deleteEvent);

        $this->productFormatter->method('formatProductDelete')->willReturn([
            ['identification_number' => 'product-' . $parentId],
        ]);
        // No surviving parent: nothing to re-queue.
        $this->productRepository->expects($this->never())->method('search');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) use ($parentId, $childId) {
                $events = $message->getEvents();

                return \count($events) === 2
                    && $events[0]['type'] === 'product.deleted'
                    && $events[0]['data']['identification_number'] === 'product-' . $parentId
                    && $events[1]['type'] === 'product.deleted'
                    && $events[1]['data']['identification_number'] === 'variation-' . $childId;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $parentResult = $this->createMock(EntityWriteResult::class);
        $parentResult->method('getPrimaryKey')->willReturn($parentId);

        // Cascade also reports the child; it must not produce a second message.
        $childResult = $this->createMock(EntityWriteResult::class);
        $childResult->method('getPrimaryKey')->willReturn($childId);

        $deletedEvent = $this->createMock(EntityDeletedEvent::class);
        $deletedEvent->method('getContext')->willReturn($context);
        $deletedEvent->method('getWriteResults')->willReturn([$parentResult, $childResult]);

        $this->subscriber->onProductDeleted($deletedEvent);
    }

    // --- Media / advanced price triggers ---

    public function testProductMediaWrittenQueuesFullProductUpdate(): void
    {
        $this->configureEnabled();
        $productId = Uuid::randomHex();

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPayload')->willReturn(['productId' => $productId, 'mediaId' => Uuid::randomHex()]);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($this->createLiveContext());
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $this->mockLoadedProduct(active: true);
        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-' . $productId],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message->getEvents()[0]['type'] === 'product.updated'))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onProductAssociationWritten($event);
    }

    public function testProductPriceDeletedQueuesUpdateForCapturedProductId(): void
    {
        $this->configureEnabled();
        $rowId = Uuid::randomHex();
        $productId = Uuid::randomHex();
        $context = $this->createLiveContext();

        $deleteEvent = $this->createMock(EntityDeleteEvent::class);
        $deleteEvent->method('getContext')->willReturn($context);
        $deleteEvent->method('getIds')->willReturnCallback(
            fn (string $entity) => $entity === 'product_price'
                ? [['id' => $rowId, 'versionId' => Defaults::LIVE_VERSION]]
                : [],
        );

        $this->connection->method('fetchAllKeyValue')->willReturn([$rowId => $productId]);

        $this->subscriber->onEntityDelete($deleteEvent);

        $this->mockLoadedProduct(active: true);
        $this->productFormatter->method('formatProduct')->willReturn([
            ['identification_number' => 'product-' . $productId],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message->getEvents()[0]['type'] === 'product.updated'))
            ->willReturn(new Envelope(new \stdClass()));

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn($rowId);

        $deletedEvent = $this->createMock(EntityDeletedEvent::class);
        $deletedEvent->method('getEntityName')->willReturn('product_price');
        $deletedEvent->method('getContext')->willReturn($context);
        $deletedEvent->method('getWriteResults')->willReturn([$writeResult]);

        $this->subscriber->onProductAssociationDeleted($deletedEvent);
    }

    public function testProductMediaDeletedWithoutCapturedMappingDispatchesNothing(): void
    {
        $this->configureEnabled();

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn(Uuid::randomHex());

        $deletedEvent = $this->createMock(EntityDeletedEvent::class);
        $deletedEvent->method('getEntityName')->willReturn('product_media');
        $deletedEvent->method('getContext')->willReturn($this->createLiveContext());
        $deletedEvent->method('getWriteResults')->willReturn([$writeResult]);

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onProductAssociationDeleted($deletedEvent);
    }

    // --- Deletes (existing behavior) ---

    public function testOnProductDeletedSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->config->method('isSyncProductsEnabled')->willReturn(true);

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onProductDeleted($event);
    }

    public function testOnProductDeletedSkipsWhenSyncDisabled(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncProductsEnabled')->willReturn(false);

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onProductDeleted($event);
    }

    public function testOnProductDeletedDispatchesEvents(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncProductsEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn(Defaults::LIVE_VERSION);

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn('product-del-001');

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $this->productFormatter->method('formatProductDelete')->willReturn([
            ['identification_number' => 'product-product-del-001'],
        ]);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof WebhookMessage
                    && count($message->getEvents()) === 1
                    && $message->getEvents()[0]['type'] === 'product.deleted';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onProductDeleted($event);
    }

    public function testOnProductDeletedSkipsNonLiveVersion(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncProductsEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn('draft-version-id');

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->expects($this->never())->method('getWriteResults');

        $this->subscriber->onProductDeleted($event);
    }

    public function testOnProductDeletedSkipsNonStringPrimaryKey(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncProductsEnabled')->willReturn(true);

        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn(Defaults::LIVE_VERSION);

        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPrimaryKey')->willReturn(['composite' => 'key']);

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onProductDeleted($event);
    }

    // --- helpers ---

    private function configureEnabled(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncProductsEnabled')->willReturn(true);

        $this->syncService->method('buildChannelContexts')->willReturn([
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
            ],
        ]);
    }

    private function createLiveContext(): Context&MockObject
    {
        $context = $this->createMock(Context::class);
        $context->method('getVersionId')->willReturn(Defaults::LIVE_VERSION);
        $context->method('getSource')->willReturn(new \Shopware\Core\Framework\Api\Context\SystemSource());

        return $context;
    }

    /**
     * @param list<EntityWriteResult&MockObject> $results
     */
    private function createWrittenEvent(array $results): EntityWrittenEvent&MockObject
    {
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($this->createLiveContext());
        $event->method('getWriteResults')->willReturn($results);

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createUpdateResult(string $primaryKey, array $payload): EntityWriteResult&MockObject
    {
        $result = $this->createMock(EntityWriteResult::class);
        $result->method('getPrimaryKey')->willReturn($primaryKey);
        $result->method('getOperation')->willReturn(EntityWriteResult::OPERATION_UPDATE);
        $result->method('getPayload')->willReturn($payload);

        return $result;
    }

    private function mockLoadedProduct(bool $active): ProductEntity&MockObject
    {
        $product = $this->createMock(ProductEntity::class);
        $product->method('getActive')->willReturn($active);
        $this->mockRepositorySearchReturns($product);

        return $product;
    }

    private function mockRepositorySearchReturns(ProductEntity&MockObject $product): void
    {
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($product);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($product));
        $this->productRepository->method('search')->willReturn($searchResult);
    }
}
