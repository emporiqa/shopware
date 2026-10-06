<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\SystemContext;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;

class SystemContextTest extends TestCase
{
    public function testCreatesASystemScopedContext(): void
    {
        $context = SystemContext::create();

        $this->assertInstanceOf(SystemSource::class, $context->getSource());
        $this->assertSame(Context::SYSTEM_SCOPE, $context->getScope());
    }

    /**
     * Context::createCLIContext() is missing on Shopware 6.6.0.x, which
     * composer.json still supports: calling it there breaks every sync with
     * "Call to undefined method". Plugin code goes through SystemContext.
     */
    public function testPluginCodeNeverCallsCreateCliContext(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/Context::createCLIContext\s*\(/', preg_replace('#/\*.*?\*/#s', '', $code) ?? $code)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders);
    }
}
