<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;

/**
 * The system context for work outside a request (syncs, commands, queue
 * handlers). Context::createCLIContext() does the same but only exists from
 * Shopware 6.6.1.0, and the plugin supports 6.6.0.0.
 */
final class SystemContext
{
    public static function create(): Context
    {
        return new Context(new SystemSource());
    }
}
