<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\FullSyncMessage;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the one product re-sync, and the one home page check, an update
 * asked for.
 *
 * The plugin's postUpdate() runs on the pre-update container, where the
 * message bus is not reachable, so it only sets a flag; the first storefront
 * render on the updated code dispatches the sync and clears it.
 */
class UpgradeResyncSubscriber implements EventSubscriberInterface
{
    public const PENDING_RESYNC_KEY = 'EmporiqaIntegration.config.pendingProductResync';

    /**
     * Before 1.3.2 a home page with no text (a storefront that is only a
     * product listing) was synced as an empty page; re-checking the sales
     * channels' navigation roots once sends its delete.
     */
    public const PENDING_HOME_PAGE_CHECK_KEY = 'EmporiqaIntegration.config.pendingHomePageCheck';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly ConfigServiceInterface $config,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly ?SyncServiceInterface $syncService = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender',
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        if ($this->syncService !== null && $this->systemConfig->getBool(self::PENDING_HOME_PAGE_CHECK_KEY)) {
            $this->queueHomePageCheck($this->syncService);
        }

        if (!$this->systemConfig->getBool(self::PENDING_RESYNC_KEY)) {
            return;
        }

        try {
            // Cleared before dispatching so a failing queue can never turn
            // every page view into another full sync
            $this->systemConfig->delete(self::PENDING_RESYNC_KEY);

            if ($this->config->isConfigured()) {
                $this->messageBus->dispatch(new FullSyncMessage('products'));
                $this->logger->info('[Emporiqa] Product re-sync queued after plugin update.');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Emporiqa] Could not queue the post-update product re-sync.', ['error' => $e->getMessage()]);
        }
    }

    private function queueHomePageCheck(SyncServiceInterface $syncService): void
    {
        try {
            $this->systemConfig->delete(self::PENDING_HOME_PAGE_CHECK_KEY);

            if (!$this->config->isConfigured()) {
                return;
            }
            $rootIds = [];
            foreach ($syncService->buildChannelContexts() as $contexts) {
                foreach ($contexts as $ctx) {
                    if (($ctx['navigationCategoryId'] ?? '') !== '') {
                        $rootIds[$ctx['navigationCategoryId']] = true;
                    }
                }
            }
            if ($rootIds !== []) {
                $this->messageBus->dispatch(new PageResyncMessage(categoryIds: array_keys($rootIds)));
                $this->logger->info('[Emporiqa] Home page check queued after plugin update.');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Emporiqa] Could not queue the post-update home page check.', ['error' => $e->getMessage()]);
        }
    }
}
