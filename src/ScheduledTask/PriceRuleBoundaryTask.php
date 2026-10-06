<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Re-syncs the products of a price rule whose date range has just started or
 * ended, so a dated sale reaches the chat when it starts and leaves it when it
 * ends (PriceRuleBoundaryTaskHandler).
 */
class PriceRuleBoundaryTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'emporiqa.price_rule_boundary';
    }

    public static function getDefaultInterval(): int
    {
        return 900;
    }
}
