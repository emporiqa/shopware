<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\EmporiqaIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
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
     * The old order tracking is not offered to new stores: a fresh install
     * stores it switched off, so its endpoint answers nothing until a
     * merchant switches it on under Advanced.
     */
    public function testANewInstallStoresOldOrderTrackingOff(): void
    {
        $stored = [];
        $this->installPlugin($stored)->install($this->createMock(InstallContext::class));

        $this->assertSame(['EmporiqaIntegration.config.orderTracking' => false], $stored);
    }

    /**
     * A reinstall that kept its data (a Store ID or a webhook secret saved,
     * so possibly using the old order tracking) keeps the setting it had, including "nothing
     * stored", which means on.
     *
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function keptSettings(): iterable
    {
        yield 'connected, never switched' => [['EmporiqaIntegration.config.storeId' => 'st_1']];
        yield 'only a secret saved, never switched' => [['EmporiqaIntegration.config.webhookSecret' => 'whsec_1']];
        yield 'switched on' => [['EmporiqaIntegration.config.orderTracking' => true]];
        yield 'switched off' => [['EmporiqaIntegration.config.orderTracking' => false]];
    }

    /**
     * @param array<string, mixed> $kept
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('keptSettings')]
    public function testAReinstallKeepsTheOrderTrackingSettingItHad(array $kept): void
    {
        $stored = $kept;
        $this->installPlugin($stored)->install($this->createMock(InstallContext::class));

        $this->assertSame($kept, $stored);
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function installPlugin(array &$stored): EmporiqaIntegration
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(static fn (string $key) => $stored[$key] ?? null);
        $systemConfig->method('set')->willReturnCallback(static function (string $key, $value) use (&$stored): void {
            $stored[$key] = $value;
        });

        $container = new Container();
        $container->set(SystemConfigService::class, $systemConfig);

        $plugin = new EmporiqaIntegration(true, __DIR__ . '/..');
        $plugin->setContainer($container);

        return $plugin;
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
