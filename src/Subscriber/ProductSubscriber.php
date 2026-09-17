<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\Event\PostProductFormatEvent;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\ProductFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

class ProductSubscriber implements EventSubscriberInterface, ResetInterface
{
    use EntityWriteEventTrait;

    private const STOCK_ONLY_FIELDS = ['stock', 'availableStock', 'available', 'sales'];
    private const IGNORED_PAYLOAD_FIELDS = ['id', 'versionId', 'updatedAt', 'createdAt'];
    private const PRODUCT_ASSOCIATION_TABLES = ['product_media', 'product_price'];

    /** @var array<string, true> Product IDs already queued for a full payload in this request */
    private array $queuedProductIds = [];

    /** @var array<string, true> Product IDs already queued for an availability payload in this request */
    private array $availabilityQueuedIds = [];

    /** @var array<string, true> Product IDs already queued for deletion in this request */
    private array $deletedProductIds = [];

    /** @var array<string, string|null> Deleted product id => parent id, captured before the rows disappear */
    private array $pendingDeleteParents = [];

    /** @var array<string, list<string>> Deleted parent id => child variant ids */
    private array $pendingDeleteChildren = [];

    /** @var array<string, array<string, string>> table => [row id => product id], captured before the rows disappear */
    private array $pendingAssociationProducts = [];

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly ProductFormatterInterface $productFormatter,
        private readonly SyncServiceInterface $syncService,
        private readonly EntityRepository $productRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        $events = [
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
            ProductEvents::PRODUCT_DELETED_EVENT => 'onProductDeleted',
            EntityDeleteEvent::class => 'onEntityDelete',
            'product_media.written' => 'onProductAssociationWritten',
            'product_media.deleted' => 'onProductAssociationDeleted',
            'product_price.written' => 'onProductAssociationWritten',
            'product_price.deleted' => 'onProductAssociationDeleted',
        ];

        // Order-driven stock changes bypass product.written (StockStorage
        // updates stock via raw SQL), so the dedicated event is required.
        if (\class_exists(ProductStockAlteredEvent::class)) {
            $events[ProductStockAlteredEvent::class] = 'onStockAltered';
        }

        return $events;
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncProductsEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $context = $event->getContext();
        $channelContexts = $this->syncService->buildChannelContexts();

        if (empty($channelContexts)) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $productId = self::primaryKeyId($result->getPrimaryKey());
            if ($productId === null) {
                continue;
            }

            if ($this->isStockOnlyWrite($result)) {
                $this->queueAvailabilityEvent($productId, $context, $channelContexts);
                continue;
            }

            if (isset($this->queuedProductIds[$productId])) {
                continue;
            }
            $this->queuedProductIds[$productId] = true;

            $payload = $result->getPayload();
            $parentId = $payload['parentId'] ?? null;

            // A variant explicitly deactivated must be removed from Emporiqa
            // individually, the parent's consolidated payload simply omits
            // inactive children, so re-syncing the parent alone would leave
            // the variant listed as available in Emporiqa.
            if ($parentId !== null && \array_key_exists('active', $payload) && $payload['active'] === false) {
                $this->dispatchVariantDeactivation($productId, $parentId, $context, $channelContexts);
                continue;
            }

            $targetId = $parentId ?? $productId;

            if ($parentId !== null) {
                if (isset($this->queuedProductIds[$targetId])) {
                    continue;
                }
                $this->queuedProductIds[$targetId] = true;
            }

            // A child write always refreshes the parent's already-existing
            // consolidated payload, the child's own INSERT/UPDATE operation
            // must never leak into the parent's event type.
            $eventType = ($parentId === null && $result->getOperation() === EntityWriteResult::OPERATION_INSERT)
                ? 'product.created'
                : 'product.updated';

            $this->queueFullProductEvent($targetId, $context, $channelContexts, $eventType);
        }
    }

    public function onStockAltered(ProductStockAlteredEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncProductsEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $channelContexts = $this->syncService->buildChannelContexts();
        if (empty($channelContexts)) {
            return;
        }

        foreach ($event->getIds() as $productId) {
            $this->queueAvailabilityEvent($productId, $event->getContext(), $channelContexts);
        }
    }

    /**
     * Captures product parent/child relations and association row owners
     * before the delete is executed, afterwards the rows are gone and
     * variants can no longer be distinguished from simple products.
     */
    public function onEntityDelete(EntityDeleteEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncProductsEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $this->captureProductParents($event);
        $this->captureAssociationProducts($event);
    }

    public function onProductDeleted(EntityDeletedEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncProductsEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $context = $event->getContext();
        $channelContexts = null;

        foreach ($event->getWriteResults() as $result) {
            $productId = self::primaryKeyId($result->getPrimaryKey());
            if ($productId === null) {
                continue;
            }

            if (isset($this->deletedProductIds[$productId])) {
                continue;
            }
            $this->deletedProductIds[$productId] = true;

            $parentId = $this->pendingDeleteParents[$productId] ?? null;

            $events = [];
            if ($parentId !== null) {
                $events[] = [
                    'type' => 'product.deleted',
                    'data' => ['identification_number' => 'variation-' . $productId],
                ];
            } else {
                // Simple product, parent, or unknown id (fallback keeps the
                // previous product- behavior for direct DB deletion edge cases).
                foreach ($this->productFormatter->formatProductDelete($productId) as $deleteData) {
                    $events[] = [
                        'type' => 'product.deleted',
                        'data' => $deleteData,
                    ];
                }

                foreach ($this->pendingDeleteChildren[$productId] ?? [] as $childId) {
                    if (isset($this->deletedProductIds[$childId])) {
                        continue;
                    }
                    $this->deletedProductIds[$childId] = true;
                    $events[] = [
                        'type' => 'product.deleted',
                        'data' => ['identification_number' => 'variation-' . $childId],
                    ];
                }
            }

            if (!empty($events)) {
                try {
                    $this->messageBus->dispatch(new WebhookMessage($events));
                } catch (\Throwable $e) {
                    $this->logger->error('[Emporiqa] Failed to queue product delete webhook.', [
                        'productId' => $productId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // A deleted variant leaves the surviving parent with a changed
            // variant set, refresh its consolidated payload.
            if ($parentId !== null
                && !\array_key_exists($parentId, $this->pendingDeleteParents)
                && !isset($this->deletedProductIds[$parentId])
            ) {
                if ($channelContexts === null) {
                    $channelContexts = $this->syncService->buildChannelContexts();
                }
                if (!empty($channelContexts)) {
                    $this->queueProductUpdate($parentId, $context, $channelContexts);
                }
            }
        }
    }

    public function onProductAssociationWritten(EntityWrittenEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncProductsEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $channelContexts = $this->syncService->buildChannelContexts();
        if (empty($channelContexts)) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $productId = $result->getPayload()['productId'] ?? null;
            if (!\is_string($productId) || $productId === '') {
                continue;
            }

            $this->queueProductUpdate($productId, $event->getContext(), $channelContexts);
        }
    }

    public function onProductAssociationDeleted(EntityDeletedEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncProductsEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $mapped = $this->pendingAssociationProducts[$event->getEntityName()] ?? [];
        if ($mapped === []) {
            return;
        }

        $channelContexts = $this->syncService->buildChannelContexts();
        if (empty($channelContexts)) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $rowId = self::primaryKeyId($result->getPrimaryKey());
            if ($rowId === null) {
                continue;
            }

            $productId = $mapped[$rowId] ?? null;
            if ($productId === null) {
                continue;
            }

            $this->queueProductUpdate($productId, $event->getContext(), $channelContexts);
        }
    }

    public function reset(): void
    {
        $this->queuedProductIds = [];
        $this->availabilityQueuedIds = [];
        $this->deletedProductIds = [];
        $this->pendingDeleteParents = [];
        $this->pendingDeleteChildren = [];
        $this->pendingAssociationProducts = [];
    }

    private function isStockOnlyWrite(EntityWriteResult $result): bool
    {
        if ($result->getOperation() === EntityWriteResult::OPERATION_INSERT) {
            return false;
        }

        $changedFields = \array_diff(\array_keys($result->getPayload()), self::IGNORED_PAYLOAD_FIELDS);
        if ($changedFields === []) {
            return false;
        }

        return \array_diff($changedFields, self::STOCK_ONLY_FIELDS) === [];
    }

    /**
     * Queues a lightweight product.availability event. A full update or a
     * delete queued for the same product (or its parent) in this request
     * supersedes the availability event.
     *
     * @param array<string, array<int, array<string, string>>> $channelContexts
     */
    private function queueAvailabilityEvent(string $productId, Context $context, array $channelContexts): void
    {
        if (isset($this->availabilityQueuedIds[$productId])
            || isset($this->queuedProductIds[$productId])
            || isset($this->deletedProductIds[$productId])
            || \array_key_exists($productId, $this->pendingDeleteParents)
        ) {
            return;
        }

        $product = $this->loadAvailabilityProduct($productId, $context);
        if ($product === null) {
            $this->logger->warning('[Emporiqa] Product not found for availability webhook.', ['productId' => $productId]);

            return;
        }

        $parentId = $product->getParentId();
        if ($parentId !== null
            && (isset($this->queuedProductIds[$parentId]) || isset($this->deletedProductIds[$parentId]))
        ) {
            return;
        }

        // null means inherited-from-parent for variants, only skip when
        // the product is explicitly inactive.
        if ($product->getActive() === false) {
            return;
        }

        $this->availabilityQueuedIds[$productId] = true;

        $entries = $this->productFormatter->formatAvailabilityEvents($product, $channelContexts);

        $events = [];
        foreach ($entries as $entry) {
            $events[] = [
                'type' => 'product.availability',
                'data' => $entry,
            ];
        }

        if (empty($events)) {
            return;
        }

        try {
            $this->messageBus->dispatch(new WebhookMessage($events));
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] Failed to queue product availability webhook.', [
                'productId' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A variant transitioning to inactive is sent as an individual
     * product.deleted, and the surviving parent is queued for a full
     * refresh so its consolidated payload drops the deactivated variant.
     *
     * @param array<string, array<int, array<string, string>>> $channelContexts
     */
    private function dispatchVariantDeactivation(string $childId, string $parentId, Context $context, array $channelContexts): void
    {
        $this->deletedProductIds[$childId] = true;

        try {
            $this->messageBus->dispatch(new WebhookMessage([
                ['type' => 'product.deleted', 'data' => ['identification_number' => 'variation-' . $childId]],
            ]));
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] Failed to queue variant deactivation webhook.', [
                'variantId' => $childId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->queueProductUpdate($parentId, $context, $channelContexts);
    }

    /**
     * @param array<string, array<int, array<string, string>>> $channelContexts
     */
    private function queueProductUpdate(string $productId, Context $context, array $channelContexts): void
    {
        if (isset($this->queuedProductIds[$productId])
            || isset($this->deletedProductIds[$productId])
            || \array_key_exists($productId, $this->pendingDeleteParents)
        ) {
            return;
        }
        $this->queuedProductIds[$productId] = true;

        $this->queueFullProductEvent($productId, $context, $channelContexts, 'product.updated');
    }

    /**
     * @param array<string, array<int, array<string, string>>> $channelContexts
     */
    private function queueFullProductEvent(string $productId, Context $context, array $channelContexts, string $eventType): void
    {
        $fullProduct = $this->loadFullProduct($productId, $context);

        if ($fullProduct === null) {
            $this->logger->warning('[Emporiqa] Product not found for webhook sync.', ['productId' => $productId]);

            return;
        }

        // A write reaching this path with a variant (e.g. media/price change
        // on a variant) must refresh the parent's consolidated payload.
        $parentId = $fullProduct->getParentId();
        if ($parentId !== null) {
            if (isset($this->queuedProductIds[$parentId]) || isset($this->deletedProductIds[$parentId])) {
                return;
            }
            $this->queuedProductIds[$parentId] = true;

            $fullProduct = $this->loadFullProduct($parentId, $context);
            if ($fullProduct === null) {
                $this->logger->warning('[Emporiqa] Product not found for webhook sync.', ['productId' => $parentId]);

                return;
            }
            $productId = $parentId;
        }

        if (!$fullProduct->getActive()) {
            $this->deletedProductIds[$productId] = true;

            $deletePayloads = $this->productFormatter->formatProductDelete($productId);
            $events = [];
            foreach ($deletePayloads as $deleteData) {
                $events[] = ['type' => 'product.deleted', 'data' => $deleteData];
            }
            if (!empty($events)) {
                try {
                    $this->messageBus->dispatch(new WebhookMessage($events));
                } catch (\Throwable $e) {
                    $this->logger->error('[Emporiqa] Failed to queue product deactivation webhook.', [
                        'productId' => $productId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return;
        }

        $formatted = $this->productFormatter->formatProduct($fullProduct, $channelContexts);

        $postFormatEvent = new PostProductFormatEvent($fullProduct, $formatted, $eventType);
        $this->eventDispatcher->dispatch($postFormatEvent);
        $formatted = $postFormatEvent->getFormattedItems();

        $events = [];
        foreach ($formatted as $item) {
            $events[] = [
                'type' => $eventType,
                'data' => $item,
            ];
        }

        try {
            $this->messageBus->dispatch(new WebhookMessage($events));
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] Failed to queue product webhook.', [
                'productId' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function loadFullProduct(string $productId, Context $context): ?ProductEntity
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('children.options.group.translations');
        $criteria->addAssociation('children.options.translations');
        $criteria->addAssociation('children.media.media');
        $criteria->addAssociation('children.cover.media');
        $criteria->addAssociation('children.seoUrls');
        $criteria->addAssociation('children.translations');
        $criteria->addAssociation('translations');
        $criteria->addAssociation('categories.translations');
        $criteria->addAssociation('manufacturer.translations');
        $criteria->addAssociation('media.media');
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('properties.group.translations');
        $criteria->addAssociation('properties.translations');
        $criteria->addAssociation('seoUrls');
        $criteria->addAssociation('visibilities');
        // Advanced (rule) prices, needed to build tier_prices. Without these
        // associations getPrices() is null and tier_prices is always empty.
        $criteria->addAssociation('prices');
        $criteria->addAssociation('children.prices');

        $product = $this->productRepository->search($criteria, $context)->getEntities()->first();

        return $product instanceof ProductEntity ? $product : null;
    }

    /**
     * Slim load for availability events, formatAvailabilityEvents only
     * needs id/parentId/productNumber/stock/availableStock/active/isCloseout
     * (all scalar, always present) plus the children collection and
     * visibilities association, not the full 15-association payload used
     * for product.created/updated. This path fires on every order (stock
     * writes), so it matters.
     */
    private function loadAvailabilityProduct(string $productId, Context $context): ?ProductEntity
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('children');
        $criteria->addAssociation('visibilities');

        $product = $this->productRepository->search($criteria, $context)->getEntities()->first();

        return $product instanceof ProductEntity ? $product : null;
    }

    private function captureProductParents(EntityDeleteEvent $event): void
    {
        $ids = $this->extractIds($event->getIds('product'));
        if ($ids === []) {
            return;
        }

        $liveVersion = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`id`)) AS `id`, LOWER(HEX(`parent_id`)) AS `parent_id`
             FROM `product`
             WHERE `id` IN (:ids) AND `version_id` = :versionId',
            ['ids' => Uuid::fromHexToBytesList($ids), 'versionId' => $liveVersion],
            ['ids' => ArrayParameterType::BINARY],
        );

        foreach ($rows as $row) {
            $this->pendingDeleteParents[(string) $row['id']] = isset($row['parent_id'])
                ? (string) $row['parent_id']
                : null;
        }

        // Children cascade with the parent but the DB rows vanish before the
        // deleted event fires, remember them for variation- delete payloads.
        $childRows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`id`)) AS `id`, LOWER(HEX(`parent_id`)) AS `parent_id`
             FROM `product`
             WHERE `parent_id` IN (:ids) AND `version_id` = :versionId',
            ['ids' => Uuid::fromHexToBytesList($ids), 'versionId' => $liveVersion],
            ['ids' => ArrayParameterType::BINARY],
        );

        foreach ($childRows as $row) {
            $childId = (string) $row['id'];
            $parentId = (string) $row['parent_id'];
            $this->pendingDeleteParents[$childId] = $parentId;
            $this->pendingDeleteChildren[$parentId][] = $childId;
        }
    }

    private function captureAssociationProducts(EntityDeleteEvent $event): void
    {
        foreach (self::PRODUCT_ASSOCIATION_TABLES as $table) {
            $ids = $this->extractIds($event->getIds($table));
            if ($ids === []) {
                continue;
            }

            $rows = $this->connection->fetchAllKeyValue(
                \sprintf('SELECT LOWER(HEX(`id`)), LOWER(HEX(`product_id`)) FROM `%s` WHERE `id` IN (:ids)', $table),
                ['ids' => Uuid::fromHexToBytesList($ids)],
                ['ids' => ArrayParameterType::BINARY],
            );

            foreach ($rows as $rowId => $mappedProductId) {
                $this->pendingAssociationProducts[$table][(string) $rowId] = (string) $mappedProductId;
            }
        }
    }

    /**
     * @param array<array<string, string>|string> $primaryKeys
     * @return list<string>
     */
    private function extractIds(array $primaryKeys): array
    {
        $ids = [];
        foreach ($primaryKeys as $primaryKey) {
            $id = \is_array($primaryKey) ? ($primaryKey['id'] ?? null) : $primaryKey;
            if (\is_string($id) && Uuid::isValid($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
