<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\AbstractRuleLoader;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Rule\Container\Container;
use Shopware\Core\Framework\Rule\DateRangeRule;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\TimeRangeRule;
use Shopware\Core\Framework\Rule\WeekdayRule;
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
 *
 * A date range (a dated sale) is evaluated like any other condition: the
 * price-rule boundary task re-syncs the products of a rule when its range
 * starts or ends, and the cache below never outlives the next boundary. Time
 * of day and weekday conditions flip far too often to re-sync, so they are
 * never public: the price published is the one that applies outside them.
 */
class GuestRuleResolver implements GuestRuleResolverInterface
{
    /**
     * Sync runs in long-lived message-queue workers, so a merchant's rule edit
     * must reach the next batch without a worker restart.
     */
    private const CACHE_TTL_SECONDS = 300;

    /** @var array<string, array{ids: list<string>, until: int}> */
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
        $now = time();
        $cached = $this->cache[$key] ?? null;
        if ($cached !== null && $now < $cached['until']) {
            return $cached['ids'];
        }

        $nextBoundary = \PHP_INT_MAX;
        $ids = $this->resolve($salesChannelId, $currencyId, $now, $nextBoundary);
        $this->cache[$key] = ['ids' => $ids, 'until' => min($now + self::CACHE_TTL_SECONDS, $nextBoundary)];

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $salesChannelId, ?string $currencyId, int $now, int &$nextBoundary): array
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
            if (!$payload instanceof Rule || $this->dependsOnTimeOfDay($payload)) {
                continue;
            }
            foreach (self::dateBoundaries($payload) as $boundary) {
                if ($boundary > $now) {
                    $nextBoundary = min($nextBoundary, $boundary);
                }
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

    private function dependsOnTimeOfDay(Rule $rule): bool
    {
        if ($rule instanceof TimeRangeRule || $rule instanceof WeekdayRule) {
            return true;
        }
        if ($rule instanceof Container) {
            foreach ($rule->getRules() as $child) {
                if ($this->dependsOnTimeOfDay($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * When the date ranges in this rule start and end, as Unix timestamps,
     * computed the way DateRangeRule::match() compares them: without "use
     * time" a range runs from midnight of its first day to midnight after
     * its last.
     *
     * @return list<int>
     */
    public static function dateBoundaries(Rule $rule): array
    {
        if ($rule instanceof Container) {
            $boundaries = [];
            foreach ($rule->getRules() as $child) {
                array_push($boundaries, ...self::dateBoundaries($child));
            }

            return $boundaries;
        }
        if (!$rule instanceof DateRangeRule) {
            return [];
        }

        $values = $rule->getVars();
        $useTime = (bool) ($values['useTime'] ?? false);

        return self::rangeBoundaries($values['fromDate'] ?? null, $values['toDate'] ?? null, $useTime);
    }

    /**
     * @return list<int>
     */
    public static function rangeBoundaries(mixed $fromDate, mixed $toDate, bool $useTime): array
    {
        $boundaries = [];
        foreach (['from' => $fromDate, 'to' => $toDate] as $side => $value) {
            try {
                $date = $value instanceof \DateTimeInterface ? $value : (\is_string($value) && $value !== '' ? new \DateTime($value) : null);
            } catch (\Throwable) {
                $date = null;
            }
            if ($date === null) {
                continue;
            }
            $date = (new \DateTime())->setTimestamp($date->getTimestamp());
            if (!$useTime) {
                if ($side === 'to') {
                    $date->add(new \DateInterval('P1D'));
                }
                $date->setTime(0, 0);
            }
            $boundaries[] = $date->getTimestamp();
        }

        return $boundaries;
    }
}
