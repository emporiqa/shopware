<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Doctrine\DBAL\Connection;

/**
 * request_id replay and rate limits of the action endpoints, kept per
 * Emporiqa store: one shop can hold several stores (store id per sales
 * channel), and one store's calls must never be answered from, or counted
 * against, another's.
 */
class ActionGuard
{
    /** Emporiqa retries a call with the same request_id for up to 10 minutes. */
    public const DEDUPE_TTL_SECONDS = 600;

    /**
     * A validly signed caller still must not walk order numbers or emails:
     * 10 lookups per value per 10-minute window is far above what one shopper
     * asking about one order needs; the store ceiling caps varied values.
     */
    public const RATE_WINDOW_SECONDS = 600;

    public const RATE_PER_VALUE = 10;

    public const RATE_PER_STORE = 300;

    /** customer_prices: per signed-in customer and per store, per window. */
    public const PRICE_RATE_PER_CUSTOMER = 30;

    public const PRICE_RATE_PER_STORE = 600;

    /** customer_info: per signed-in customer and per store, per window. */
    public const INFO_RATE_PER_CUSTOMER = 30;

    public const INFO_RATE_PER_STORE = 600;

    private const STORE_BUCKETS = ['store', 'customer_prices:store', 'customer_info:store'];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{0: int, 1: string}|null http code and raw body already given to this request_id
     */
    public function remembered(string $storeId, string $requestId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT `http_code`, `response` FROM `emporiqa_action_request` WHERE `request_hash` = :hash AND `created_at` >= :since',
            ['hash' => hash('sha256', $storeId . '|' . $requestId), 'since' => time() - self::DEDUPE_TTL_SECONDS],
        );

        return \is_array($row) ? [(int) $row['http_code'], (string) $row['response']] : null;
    }

    public function remember(string $storeId, string $requestId, int $httpCode, string $body): void
    {
        $now = time();
        $this->connection->executeStatement(
            'DELETE FROM `emporiqa_action_request` WHERE `created_at` < :since',
            ['since' => $now - self::DEDUPE_TTL_SECONDS],
        );
        $this->connection->executeStatement(
            'REPLACE INTO `emporiqa_action_request` (`request_hash`, `http_code`, `response`, `created_at`) VALUES (:hash, :code, :body, :now)',
            ['hash' => hash('sha256', $storeId . '|' . $requestId), 'code' => $httpCode, 'body' => $body, 'now' => $now],
        );
    }

    /**
     * order_status: count this call against its order number, its email and
     * the store.
     *
     * @return array{scope: string, retry_after: int}|null
     */
    public function rateLimitHit(string $storeId, string $orderNumber, string $email, ?int $now = null): ?array
    {
        $buckets = ['store' => self::RATE_PER_STORE];
        foreach (['order_number' => $orderNumber, 'email' => $email] as $name => $value) {
            $value = mb_strtolower(trim($value));
            if ($value !== '') {
                $buckets[$name . ':' . $value] = self::RATE_PER_VALUE;
            }
        }

        return $this->count($storeId, $buckets, $now);
    }

    /**
     * customer_prices: count this call against the customer and the store.
     *
     * @return array{scope: string, retry_after: int}|null
     */
    public function customerPriceLimitHit(string $storeId, string $customerId, ?int $now = null): ?array
    {
        return $this->count($storeId, [
            'customer_prices:store' => self::PRICE_RATE_PER_STORE,
            'customer_prices:customer:' . strtolower($customerId) => self::PRICE_RATE_PER_CUSTOMER,
        ], $now);
    }

    /**
     * customer_info: count this call against the customer and the store.
     *
     * @return array{scope: string, retry_after: int}|null
     */
    public function customerInfoLimitHit(string $storeId, string $customerId, ?int $now = null): ?array
    {
        return $this->count($storeId, [
            'customer_info:store' => self::INFO_RATE_PER_STORE,
            'customer_info:customer:' . strtolower($customerId) => self::INFO_RATE_PER_CUSTOMER,
        ], $now);
    }

    /**
     * Count one hit in every bucket and say which limit it is now over: null
     * when under every limit, scope "store" when a store ceiling is hit,
     * "value" when only a per-value limit is, with the seconds until the
     * window ends.
     *
     * Fails closed: a counter that cannot be written or read back counts as
     * the store ceiling, since an uncounted lookup is what the limit stops.
     *
     * @param array<string, int> $buckets bucket name => limit
     *
     * @return array{scope: string, retry_after: int}|null
     */
    private function count(string $storeId, array $buckets, ?int $now): ?array
    {
        $now ??= time();
        $windowEnd = $now - ($now % self::RATE_WINDOW_SECONDS) + self::RATE_WINDOW_SECONDS;

        if (random_int(1, 20) === 1) {
            try {
                $this->connection->executeStatement(
                    'DELETE FROM `emporiqa_action_rate` WHERE `expires_at` <= :now',
                    ['now' => $now],
                );
            } catch (\Throwable) {
                // Cleanup only; the counters below decide.
            }
        }

        $scope = null;
        foreach ($buckets as $bucket => $limit) {
            $hash = hash('sha256', $storeId . '|' . $bucket . '|' . $windowEnd);
            try {
                $this->connection->executeStatement(
                    'INSERT INTO `emporiqa_action_rate` (`bucket_hash`, `hits`, `expires_at`) VALUES (:hash, 1, :expires)'
                    . ' ON DUPLICATE KEY UPDATE `hits` = `hits` + 1',
                    ['hash' => $hash, 'expires' => $windowEnd],
                );
                $hits = (int) $this->connection->fetchOne(
                    'SELECT `hits` FROM `emporiqa_action_rate` WHERE `bucket_hash` = :hash',
                    ['hash' => $hash],
                );
            } catch (\Throwable) {
                $hits = 0;
            }
            $isStore = \in_array($bucket, self::STORE_BUCKETS, true);
            if ($hits < 1) {
                $scope = 'store';
            } elseif ($hits > $limit) {
                $scope = $isStore ? 'store' : ($scope ?? 'value');
            }
        }

        return $scope === null ? null : ['scope' => $scope, 'retry_after' => max(1, $windowEnd - $now)];
    }
}
