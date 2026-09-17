<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\PageSyncRegistry;
use PHPUnit\Framework\TestCase;

class PageSyncRegistryTest extends TestCase
{
    public function testClaimReturnsOnlyIdsNotClaimedBefore(): void
    {
        $registry = new PageSyncRegistry();

        $this->assertSame(['a', 'b'], $registry->claim(['a', 'b']));
        $this->assertSame(['c'], $registry->claim(['b', 'c']));
        $this->assertSame([], $registry->claim(['a']));
    }

    public function testResetForgetsClaims(): void
    {
        $registry = new PageSyncRegistry();
        $registry->claim(['a']);
        $registry->reset();

        $this->assertSame(['a'], $registry->claim(['a']));
    }
}
