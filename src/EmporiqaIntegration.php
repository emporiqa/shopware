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
    public const PLUGIN_VERSION = '1.2.5';

    /**
     * Before 1.2.4, tier prices from customer-group rules were synced as public
     * prices; before 1.2.5, prices in a non-default currency were sent without
     * the exchange rate. Emporiqa keeps them until each product is synced
     * again, so an update from an affected version schedules one product re-sync.
     */
    private const FIRST_VERSION_WITH_CORRECT_PRICES = '1.2.5';

    public function postUpdate(UpdateContext $updateContext): void
    {
        parent::postUpdate($updateContext);

        if (version_compare($updateContext->getCurrentPluginVersion(), self::FIRST_VERSION_WITH_CORRECT_PRICES, '>=')) {
            return;
        }

        try {
            $this->container->get('Shopware\Core\System\SystemConfig\SystemConfigService')
                ->set(UpgradeResyncSubscriber::PENDING_RESYNC_KEY, true);
        } catch (\Throwable $e) {
            // Never fail the update; the changelog also asks for a manual product sync.
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

        $this->purgeOrderTrackingMarkers();
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
