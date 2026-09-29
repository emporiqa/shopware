<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\AbstractRuleLoader;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;

/**
 * Advanced prices belong to rules, and most rules are not public: "Customer
 * group is Dealer" prices must never reach a guest. The storefront decides
 * which rules apply by evaluating them against the visitor's context and cart,
 * so this does the same for an anonymous visitor with an empty cart.
 *
 * Every failure resolves to "no rules": dropping a public tier is harmless,
 * publishing a restricted one is not.
 */
class GuestRuleResolver implements GuestRuleResolverInterface
{
    /**
     * Sync runs in long-lived message-queue workers, so a merchant's rule edit
     * must reach the next batch without a worker restart.
     */
    private const CACHE_TTL_SECONDS = 300;

    /** @var array<string, array{ids: list<string>, at: int}> */
    private array $cache = [];

    public function __construct(
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly AbstractRuleLoader $ruleLoader,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getGuestRuleIds(string $salesChannelId, ?string $currencyId): array
    {
        $key = $salesChannelId . '|' . ($currencyId ?? '');
        $cached = $this->cache[$key] ?? null;
        if ($cached !== null && time() - $cached['at'] < self::CACHE_TTL_SECONDS) {
            return $cached['ids'];
        }

        $ids = $this->resolve($salesChannelId, $currencyId);
        $this->cache[$key] = ['ids' => $ids, 'at' => time()];

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $salesChannelId, ?string $currencyId): array
    {
        $options = [];
        if ($currencyId !== null && $currencyId !== '') {
            $options[SalesChannelContextService::CURRENCY_ID] = $currencyId;
        }

        try {
            $salesChannelContext = $this->salesChannelContextFactory->create(Uuid::randomHex(), $salesChannelId, $options);
            // Loaded highest priority first, which is the order the storefront's
            // price calculator walks when it picks a rule's prices
            $rules = $this->ruleLoader->load($salesChannelContext->getContext())->filterForContext();
        } catch (\Throwable $e) {
            $this->logger->warning('Emporiqa: could not resolve guest price rules, tier prices skipped', [
                'salesChannelId' => $salesChannelId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $scope = new CartRuleScope(new Cart($salesChannelContext->getToken()), $salesChannelContext);

        $ids = [];
        foreach ($rules as $rule) {
            $payload = $rule->getPayload();
            if (!$payload instanceof Rule) {
                continue;
            }

            try {
                if ($payload->match($scope)) {
                    $ids[] = $rule->getId();
                }
            } catch (\Throwable $e) {
                $this->logger->info('Emporiqa: price rule could not be evaluated for guests, treated as not public', [
                    'ruleId' => $rule->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $ids;
    }
}
