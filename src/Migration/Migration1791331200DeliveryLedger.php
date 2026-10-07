<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The build time of the newest full state of a product, variant or page that
 * reached Emporiqa (DeliveryLedger), so a delayed re-send of an older version
 * is dropped. `delivered_at` holds that build time.
 * Rows expire after a few hours. Dropped on uninstall unless user data is kept.
 */
class Migration1791331200DeliveryLedger extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791331200;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `emporiqa_webhook_delivery` (
                `item_hash` CHAR(64) NOT NULL,
                `delivered_at` DECIMAL(17,6) NOT NULL,
                `expires_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`item_hash`),
                KEY `idx.emporiqa_webhook_delivery.expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
