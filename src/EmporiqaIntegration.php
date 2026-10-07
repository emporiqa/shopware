<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\Subscriber\UpgradeResyncSubscriber;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class EmporiqaIntegration extends Plugin
{
    public const PLUGIN_VERSION = '1.3.2';

    /**
     * Before 1.2.4, tier prices from customer-group rules were synced as public
     * prices; before 1.2.5, prices in a non-default currency were sent without
     * the exchange rate; before 1.3.0, a guest rule's advanced prices, inherited
     * variant prices and date-bound rules were read wrongly. Emporiqa keeps
     * them until each product is synced again, so an update from an affected
     * version schedules one product re-sync.
     */
    private const FIRST_VERSION_WITH_CORRECT_PRICES = '1.3.0';

    /** Before 1.3.2, a home page with no text was synced as an empty page. */
    private const FIRST_VERSION_WITHOUT_EMPTY_HOME_PAGE = '1.3.2';

    public function postUpdate(UpdateContext $updateContext): void
    {
        parent::postUpdate($updateContext);

        $from = $updateContext->getCurrentPluginVersion();
        $flags = [];
        if (version_compare($from, self::FIRST_VERSION_WITH_CORRECT_PRICES, '<')) {
            $flags[] = UpgradeResyncSubscriber::PENDING_RESYNC_KEY;
        }
        if (version_compare($from, self::FIRST_VERSION_WITHOUT_EMPTY_HOME_PAGE, '<')) {
            $flags[] = UpgradeResyncSubscriber::PENDING_HOME_PAGE_CHECK_KEY;
        }

        foreach ($flags as $flag) {
            try {
                $this->container->get('Shopware\Core\System\SystemConfig\SystemConfigService')->set($flag, true);
            } catch (\Throwable $e) {
                // Never fail the update; a manual sync from the Emporiqa page does the same.
            }
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->container->get('Shopware\Core\System\SystemConfig\SystemConfigService')
            ->deletePluginConfiguration($this);

        $this->purgeStoredState();
        $this->purgeOrderTrackingMarkers();
        $this->dropActionTables();
    }

    /**
     * deletePluginConfiguration() only removes the keys declared in
     * config.xml; the connection state, rules status, channel and language
     * choices and sync sessions the plugin stores itself would otherwise
     * survive and come back on a reinstall.
     */
    private function purgeStoredState(): void
    {
        try {
            /** @var Connection $connection */
            $connection = $this->container->get(Connection::class);
            $connection->executeStatement(
                'DELETE FROM `system_config` WHERE `configuration_key` LIKE :prefix',
                ['prefix' => 'EmporiqaIntegration.%'],
            );
        } catch (\Throwable $e) {
            // Best-effort: never fail the uninstall over cleanup.
        }
    }

    /**
     * The action endpoints' replay and rate-limit tables
     * (Migration1759622400ActionTables) and the webhook delivery ledger
     * (Migration1791331200DeliveryLedger).
     */
    private function dropActionTables(): void
    {
        try {
            /** @var Connection $connection */
            $connection = $this->container->get(Connection::class);
            $connection->executeStatement('DROP TABLE IF EXISTS `emporiqa_action_request`, `emporiqa_action_rate`, `emporiqa_webhook_delivery`');
        } catch (\Throwable $e) {
            // Best-effort: never fail the uninstall over cleanup.
        }
    }

    /**
     * Best-effort removal of plugin markers written onto order custom fields
     * (emporiqa_session_id, emporiqa_order_tracked) so no plugin-owned data
     * lingers on orders after uninstall. Never fails the uninstall.
     */
    private function purgeOrderTrackingMarkers(): void
    {
        try {
            /** @var Connection $connection */
            $connection = $this->container->get(Connection::class);
            $connection->executeStatement(
                "UPDATE `order` SET custom_fields = JSON_REMOVE(custom_fields, '$.emporiqa_session_id', '$.emporiqa_order_tracked') "
                . "WHERE JSON_CONTAINS_PATH(custom_fields, 'one', '$.emporiqa_session_id', '$.emporiqa_order_tracked')",
            );
        } catch (\Throwable $e) {
            // Best-effort: never fail the uninstall over marker cleanup.
        }
    }
}
