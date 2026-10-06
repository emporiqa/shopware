<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

interface SyncServiceInterface
{
    /**
     * @return array{success: bool, products: int, events: int, errors: string[]}
     */
    public function syncProducts(?callable $progressCallback = null, bool $dryRun = false): array;

    /**
     * @return array{success: bool, pages: int, events: int, errors: string[]}
     */
    public function syncPages(?callable $progressCallback = null, bool $dryRun = false): array;

    /**
     * @return array{success: bool, products: int, pages: int, events: int, errors: string[]}
     */
    public function syncAll(?callable $progressCallback = null, bool $dryRun = false): array;

    /**
     * Build channel contexts from sales channels and channel mapping config.
     *
     * @return array<string, array<int, array<string, string>>> Grouped by Emporiqa channel key
     */
    public function buildChannelContexts(): array;

    /**
     * Count active items for an entity.
     *
     * 'products' counts active parent products; 'pages' counts active landing
     * pages plus active shop-page categories combined.
     *
     * @param string $entity 'products' or 'pages'
     */
    public function countItems(string $entity): int;

    /**
     * Process one batch of an entity's driven bulk sync.
     *
     * Same criteria and id order as the full sync loops, paged by id after
     * $cursor (keyset), never by offset. For 'pages', landing pages first,
     * then shop-page categories.
     *
     * @param string $entity 'products' or 'pages'
     * @param string $cursor '' for the first batch, then the previous answer's nextCursor
     * @param string $sessionId Sync session id to stamp onto formatted events
     * @param int|null $batchSize Page size pinned at session start; null falls back to the current config value
     *
     * @return array{success: bool, processed: int, events: int, error?: string, nextCursor: ?string}
     */
    public function syncBatch(string $entity, string $cursor, string $sessionId, ?int $batchSize = null): array;

    /**
     * Send product.updated for these parent (or simple) products now, outside
     * any sync session. Inactive or unknown ids are skipped.
     *
     * @param list<string> $productIds
     *
     * @return int products sent
     */
    public function resyncProducts(array $productIds): int;
}
