<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests;

use Emporiqa\ShopwarePlugin\EmporiqaIntegration;
use PHPUnit\Framework\TestCase;

class PluginVersionTest extends TestCase
{
    public function testPluginVersionConstantMatchesComposerJson(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);

        // The constant is reported to Emporiqa as plugin_version and User-Agent
        $this->assertSame($composer['version'], EmporiqaIntegration::PLUGIN_VERSION);
    }
}
