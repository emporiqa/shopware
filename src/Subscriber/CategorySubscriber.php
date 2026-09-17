<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\PageSyncRegistry;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues changed categories for the background page sync; only categories that
 * are shop pages produce webhooks there.
 */
class CategorySubscriber implements EventSubscriberInterface
{
    use EntityWriteEventTrait;

    /** Payload fields that can turn a shop page into a non-page (or back). */
    private const STRUCTURAL_FIELDS = ['cmsPageId', 'type', 'active', 'parentId'];

    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly CmsPageFormatterInterface $cmsPageFormatter,
        private readonly PageSyncRegistry $registry,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After Shopware's entity indexing (priority 1000), so SEO URLs of new or
            // moved categories exist when the page is processed.
            EntityWrittenContainerEvent::class => ['onEntityWrittenContainer', -100],
            CategoryEvents::CATEGORY_DELETED_EVENT => 'onCategoryDeleted',
        ];
    }

    public function onEntityWrittenContainer(EntityWrittenContainerEvent $event): void
    {
        foreach ($event->getEvents() ?? [] as $nested) {
            $written = self::writtenEventOf($nested, CategoryDefinition::ENTITY_NAME);
            if ($written !== null) {
                $this->onCategoryWritten($written);
            }
        }
    }

    public function onCategoryWritten(EntityWrittenEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $categoryIds = [];
        $createdIds = [];
        $structuralIds = [];
        foreach ($event->getWriteResults() as $result) {
            $categoryId = self::primaryKeyId($result->getPrimaryKey());
            if ($categoryId === null) {
                continue;
            }

            $categoryIds[$categoryId] = $categoryId;
            if ($result->getOperation() === EntityWriteResult::OPERATION_INSERT) {
                $createdIds[$categoryId] = $categoryId;
            }
            if (array_intersect_key($result->getPayload(), array_flip(self::STRUCTURAL_FIELDS)) !== []) {
                $structuralIds[$categoryId] = $categoryId;
            }
        }

        // Ids the SEO URL listener already queued in this request are skipped, except
        // created or structurally changed ones: only this subscriber knows those flags,
        // and the handler needs them to skip or send the right delete.
        $fresh = $this->registry->claim(array_values($categoryIds));
        $categoryIds = array_values(array_unique(array_merge($fresh, array_values($createdIds), array_values($structuralIds))));
        if ($categoryIds === []) {
            return;
        }

        try {
            $this->messageBus->dispatch(new PageResyncMessage(
                categoryIds: $categoryIds,
                createdIds: array_values($createdIds),
                structuralCategoryIds: array_values($structuralIds),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] Failed to queue shop page sync.', [
                'categoryIds' => $categoryIds,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onCategoryDeleted(EntityDeletedEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $categoryId = self::primaryKeyId($result->getPrimaryKey());
            if ($categoryId === null) {
                continue;
            }

            $events = [];
            foreach ($this->cmsPageFormatter->formatPageDelete($categoryId) as $deleteData) {
                $events[] = ['type' => 'page.deleted', 'data' => $deleteData];
            }

            if ($events === []) {
                continue;
            }

            try {
                $this->messageBus->dispatch(new WebhookMessage($events));
            } catch (\Throwable $e) {
                $this->logger->error('[Emporiqa] Failed to queue shop page delete webhook.', [
                    'categoryId' => $categoryId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
