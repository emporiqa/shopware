<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Controller;

use Emporiqa\ShopwarePlugin\Controller\Admin\ConnectController;
use Emporiqa\ShopwarePlugin\Controller\Admin\SyncController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

class AdminRouteAclTest extends TestCase
{
    /**
     * @return iterable<array{class-string}>
     */
    public static function adminControllers(): iterable
    {
        yield [ConnectController::class];
        yield [SyncController::class];
    }

    /**
     * Every admin API route of the plugin (settings, secret, connect, sync)
     * needs the plugin-maintenance privilege: any admin API user or
     * integration could otherwise repoint the webhook URL or replace the
     * secret. Set on the class, so a new route cannot miss it.
     *
     * @param class-string $class
     */
    #[DataProvider('adminControllers')]
    public function testRoutesRequireThePluginMaintainPrivilege(string $class): void
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(Route::class);
        $this->assertCount(1, $attributes);
        $defaults = $attributes[0]->getArguments()['defaults'] ?? [];

        $this->assertSame(['system.plugin_maintain'], $defaults['_acl'] ?? null);
        $this->assertSame(['api'], $defaults['_routeScope'] ?? null);
    }
}
