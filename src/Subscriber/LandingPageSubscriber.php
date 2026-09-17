<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\PageSyncRegistry;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\LandingPage\LandingPageDefinition;
use Shopware\Core\Content\LandingPage\LandingPageEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues changed landing pages for the background page sync; the formatting and
 * webhooks happen in PageResyncMessageHandler.
 */
class LandingPageSubscriber implements EventSubscriberInterface
{
    use EntityWriteEventTrait;

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
            // renamed landing pages exist when the page is processed.
            EntityWrittenContainerEvent::class => ['onEntityWrittenContainer', -100],
            LandingPageEvents::LANDING_PAGE_DELETED_EVENT => 'onLandingPageDeleted',
        ];
    }

    public function onEntityWrittenContainer(EntityWrittenContainerEvent $event): void
    {
        foreach ($event->getEvents() ?? [] as $nested) {
            $written = self::writtenEventOf($nested, LandingPageDefinition::ENTITY_NAME);
            if ($written !== null) {
                $this->onLandingPageWritten($written);
            }
        }
    }

    public function onLandingPageWritten(EntityWrittenEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $pageIds = [];
        $createdIds = [];
        foreach ($event->getWriteResults() as $result) {
            $pageId = self::primaryKeyId($result->getPrimaryKey());
            if ($pageId === null) {
                continue;
            }

            $pageIds[$pageId] = $pageId;
            if ($result->getOperation() === EntityWriteResult::OPERATION_INSERT) {
                $createdIds[$pageId] = $pageId;
            }
        }

        // Ids the SEO URL listener already queued in this request are skipped, except
        // created ones: the handler must know nothing exists remotely for them yet.
        $fresh = $this->registry->claim(array_values($pageIds));
        $pageIds = array_values(array_unique(array_merge($fresh, array_values($createdIds))));
        if ($pageIds === []) {
            return;
        }

        try {
            $this->messageBus->dispatch(new PageResyncMessage(
                landingPageIds: $pageIds,
                createdIds: array_values($createdIds),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] Failed to queue landing page sync.', [
                'pageIds' => $pageIds,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onLandingPageDeleted(EntityDeletedEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $pageId = self::primaryKeyId($result->getPrimaryKey());
            if ($pageId === null) {
                continue;
            }

            $events = [];
            foreach ($this->cmsPageFormatter->formatPageDelete($pageId) as $deleteData) {
                $events[] = ['type' => 'page.deleted', 'data' => $deleteData];
            }

            if ($events === []) {
                continue;
            }

            try {
                $this->messageBus->dispatch(new WebhookMessage($events));
            } catch (\Throwable $e) {
                $this->logger->error('[Emporiqa] Failed to queue page delete webhook.', [
                    'pageId' => $pageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
