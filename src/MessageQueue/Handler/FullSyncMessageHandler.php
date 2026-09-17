<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\MessageQueue\Handler;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\FullSyncMessage;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FullSyncMessageHandler
{
    public function __construct(
        private readonly SyncServiceInterface $syncService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(FullSyncMessage $message): void
    {
        $entity = $message->getEntity();

        $this->logger->info('[Emporiqa] Full sync started via queue.', ['entity' => $entity]);

        $result = match ($entity) {
            'products' => $this->syncService->syncProducts(),
            'pages' => $this->syncService->syncPages(),
            default => $this->syncService->syncAll(),
        };

        if (!empty($result['errors'])) {
            $this->logger->warning('[Emporiqa] Full sync completed with errors.', [
                'entity' => $entity,
                'errors' => $result['errors'],
            ]);

            return;
        }

        $this->logger->info('[Emporiqa] Full sync completed successfully.', [
            'entity' => $entity,
            'products' => $result['products'] ?? null,
            'pages' => $result['pages'] ?? null,
            'events' => $result['events'],
        ]);
    }
}
