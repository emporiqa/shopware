<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\EmporiqaIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\Container;

class PluginLifecycleTest extends TestCase
{
    /**
     * An uninstall without "keep data" leaves no plugin row in system_config,
     * including the keys the plugin stores itself (connection state, rules
     * status, the old order tracking switch, sync sessions), so a reinstall
     * starts clean. Keeping data touches nothing.
     */
    public function testUninstallWithoutKeepingDataRemovesEveryStoredKey(): void
    {
        $statements = [];
        $plugin = $this->plugin($statements, expectConfigDelete: true);

        $plugin->uninstall($this->context(keepUserData: false));

        $purge = array_values(array_filter($statements, static fn (array $s): bool => str_contains($s[0], 'system_config')));
        $this->assertCount(1, $purge);
        $this->assertStringStartsWith('DELETE FROM `system_config`', $purge[0][0]);
        $this->assertSame(['prefix' => 'EmporiqaIntegration.%'], $purge[0][1]);
        $drops = implode(' ', array_column(array_filter($statements, static fn (array $s): bool => str_contains($s[0], 'DROP TABLE')), 0));
        foreach (['emporiqa_action_request', 'emporiqa_action_rate', 'emporiqa_webhook_delivery'] as $table) {
            $this->assertStringContainsString('`' . $table . '`', $drops);
        }
    }

    public function testUninstallKeepingDataRunsNoStatement(): void
    {
        $statements = [];
        $plugin = $this->plugin($statements, expectConfigDelete: false);

        $plugin->uninstall($this->context(keepUserData: true));

        $this->assertSame([], $statements);
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $statements
     */
    private function plugin(array &$statements, bool $expectConfigDelete): EmporiqaIntegration
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 0;
            },
        );

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->expects($expectConfigDelete ? $this->once() : $this->never())->method('deletePluginConfiguration');

        $container = new Container();
        $container->set(Connection::class, $connection);
        $container->set(SystemConfigService::class, $systemConfig);

        $plugin = new EmporiqaIntegration(true, __DIR__ . '/..');
        $plugin->setContainer($container);

        return $plugin;
    }

    private function context(bool $keepUserData): UninstallContext
    {
        $context = $this->createMock(UninstallContext::class);
        $context->method('keepUserData')->willReturn($keepUserData);

        return $context;
    }
}
