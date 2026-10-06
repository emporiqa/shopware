<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Tables of the order_status endpoint (ActionGuard): request_id replay and
 * rate-limit counters. Dropped on uninstall unless user data is kept.
 */
class Migration1759622400ActionTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1759622400;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `emporiqa_action_request` (
                `request_hash` CHAR(64) NOT NULL,
                `http_code` SMALLINT UNSIGNED NOT NULL,
                `response` MEDIUMTEXT NOT NULL,
                `created_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`request_hash`),
                KEY `idx.emporiqa_action_request.created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `emporiqa_action_rate` (
                `bucket_hash` CHAR(64) NOT NULL,
                `hits` INT UNSIGNED NOT NULL,
                `expires_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`bucket_hash`),
                KEY `idx.emporiqa_action_rate.expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
