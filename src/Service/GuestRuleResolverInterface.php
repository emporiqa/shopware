<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

interface GuestRuleResolverInterface
{
    /**
     * Rule ids a guest shopper with an empty cart matches in this sales channel
     * and currency, highest priority first (the order the storefront uses to
     * pick advanced prices).
     *
     * @return list<string>
     */
    public function getGuestRuleIds(string $salesChannelId, ?string $currencyId): array;
}
