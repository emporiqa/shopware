<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class EmporiqaIntegration extends Plugin
{
    public const PLUGIN_VERSION = '1.2.1';

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
