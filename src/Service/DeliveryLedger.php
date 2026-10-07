<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Remembers, per product, variant or page, the build time of the newest full
 * state that reached Emporiqa, so a delayed re-send of an older version is
 * dropped instead of overwriting a newer one sent in the meantime (an edit
 * saved while Emporiqa was down, saved again after it came back).
 *
 * Build time, never delivery time: when two versions both wait for a retry,
 * the older one may be delivered first, and that must not make the newer
 * one look stale.
 *
 * Kept in the database (emporiqa_webhook_delivery), not in a cache: Shopware's
 * object cache is per process in the dev environment and emptied by every
 * cache:clear. Anything failing here keeps every event, which is how
 * re-sends behaved before.
 */
class DeliveryLedger
{
    /** Longer than every retry chain of WebhookMessageHandler (about 2.5 hours). */
    public const TTL_SECONDS = 4 * 3600;

    private const PENDING_KEY = 'retry-pending';

    /** Events that carry the item's whole state; product.availability carries only stock. */
    private const FULL_STATE_TYPES = [
        'product.created',
        'product.updated',
        'product.deleted',
        'page.created',
        'page.updated',
        'page.deleted',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Record that these events, built at $builtAt, reached Emporiqa.
     *
     * @param array<int, array{type: string, data: array<string, mixed>}> $events
     * @param float $builtAt when the events were built (Unix time with microseconds)
     * @param bool $onlyWhileRetrying skip the writes while no re-send is waiting (bulk syncs)
     */
    public function recordDelivered(array $events, float $builtAt, bool $onlyWhileRetrying = false): void
    {
        $hashes = [];
        foreach ($events as $event) {
            $hash = self::hash($event);
            if ($hash !== null) {
                $hashes[$hash] = true;
            }
        }
        if ($hashes === []) {
            return;
        }

        try {
            $now = time();
            if ($onlyWhileRetrying && !$this->retryPending($now)) {
                return;
            }
            $this->write(array_keys($hashes), $builtAt, $now);
            if (random_int(1, 50) === 1) {
                $this->connection->executeStatement('DELETE FROM `emporiqa_webhook_delivery` WHERE `expires_at` <= :now', ['now' => $now]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Emporiqa] Could not record delivered webhook events: ' . $e::class);
        }
    }

    /**
     * Note that a re-send is waiting, so bulk syncs record what they deliver.
     */
    public function markRetryPending(): void
    {
        try {
            $this->write([hash('sha256', self::PENDING_KEY)], microtime(true), time());
        } catch (\Throwable $e) {
            $this->logger->warning('[Emporiqa] Could not mark a pending webhook retry: ' . $e::class);
        }
    }

    /**
     * The events not superseded by a delivered version of the same item that
     * was built after them ($builtAt, Unix time with microseconds).
     *
     * @param array<int, array{type: string, data: array<string, mixed>}> $events
     *
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function withoutSuperseded(array $events, float $builtAt): array
    {
        $hashes = [];
        foreach ($events as $i => $event) {
            $hash = self::hash($event, true);
            if ($hash !== null) {
                $hashes[$i] = $hash;
            }
        }
        if ($hashes === []) {
            return $events;
        }

        try {
            /** @var array<string, string> $delivered item hash => delivered at */
            $delivered = $this->connection->fetchAllKeyValue(
                'SELECT `item_hash`, `delivered_at` FROM `emporiqa_webhook_delivery` WHERE `item_hash` IN (:hashes) AND `expires_at` > :now',
                ['hashes' => array_values(array_unique($hashes)), 'now' => time()],
                ['hashes' => ArrayParameterType::STRING],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Emporiqa] Could not read delivered webhook events: ' . $e::class);

            return $events;
        }

        $kept = [];
        foreach ($events as $i => $event) {
            $deliveredAt = isset($hashes[$i]) ? ($delivered[$hashes[$i]] ?? null) : null;
            if ($deliveredAt !== null && (float) $deliveredAt > $builtAt) {
                continue;
            }
            $kept[] = $event;
        }

        return $kept;
    }

    private function retryPending(int $now): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM `emporiqa_webhook_delivery` WHERE `item_hash` = :hash AND `expires_at` > :now',
            ['hash' => hash('sha256', self::PENDING_KEY), 'now' => $now],
        ) !== false;
    }

    /**
     * @param list<string> $hashes
     */
    private function write(array $hashes, float $builtAt, int $now): void
    {
        $values = [];
        $params = ['at' => sprintf('%.6F', $builtAt), 'expires' => $now + self::TTL_SECONDS];
        foreach ($hashes as $i => $hash) {
            $values[] = '(:h' . $i . ', :at, :expires)';
            $params['h' . $i] = $hash;
        }
        $this->connection->executeStatement(
            'INSERT INTO `emporiqa_webhook_delivery` (`item_hash`, `delivered_at`, `expires_at`) VALUES ' . implode(', ', $values)
            . ' ON DUPLICATE KEY UPDATE `delivered_at` = GREATEST(`delivered_at`, VALUES(`delivered_at`)), `expires_at` = VALUES(`expires_at`)',
            $params,
        );
    }

    /**
     * @param array{type?: mixed, data?: mixed} $event
     * @param bool $anyType also for product.availability, which a newer full state supersedes
     */
    private static function hash(array $event, bool $anyType = false): ?string
    {
        $type = \is_string($event['type'] ?? null) ? $event['type'] : '';
        if (!$anyType && !\in_array($type, self::FULL_STATE_TYPES, true)) {
            return null;
        }
        $id = \is_array($event['data'] ?? null) ? ($event['data']['identification_number'] ?? null) : null;
        if (!\is_string($id) || !preg_match('/^(product|variation|page)-/', $id)) {
            return null;
        }

        return hash('sha256', $id);
    }
}
