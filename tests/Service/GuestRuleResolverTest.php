<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\GuestRuleResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\AbstractRuleLoader;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Rule\Container\AndRule;
use Shopware\Core\Framework\Rule\Container\OrRule;
use Shopware\Core\Framework\Rule\DateRangeRule;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleScope;
use Shopware\Core\Framework\Rule\TimeRangeRule;
use Shopware\Core\Framework\Rule\WeekdayRule;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class GuestRuleResolverTest extends TestCase
{
    private AbstractSalesChannelContextFactory&MockObject $contextFactory;
    private AbstractRuleLoader&MockObject $ruleLoader;

    protected function setUp(): void
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getToken')->willReturn('token');
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $this->contextFactory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $this->contextFactory->method('create')->willReturn($salesChannelContext);

        $this->ruleLoader = $this->createMock(AbstractRuleLoader::class);
    }

    public function testReturnsOnlyRulesAGuestMatchesInLoaderOrder(): void
    {
        $this->ruleLoader->method('load')->willReturn(new RuleCollection([
            $this->rule('rule-high', true),
            $this->rule('rule-dealer', false),
            $this->rule('rule-low', true),
        ]));

        $this->assertSame(['rule-high', 'rule-low'], $this->resolver()->getGuestRuleIds('sc-1', 'curr-eur'));
    }

    public function testRuleThatCannotBeEvaluatedIsTreatedAsNotPublic(): void
    {
        $this->ruleLoader->method('load')->willReturn(new RuleCollection([
            $this->rule('rule-broken', new \RuntimeException('condition needs a customer')),
            $this->rule('rule-public', true),
        ]));

        $this->assertSame(['rule-public'], $this->resolver()->getGuestRuleIds('sc-1', 'curr-eur'));
    }

    /**
     * Time of day and weekday conditions flip too often to re-sync, so a rule
     * using them is never public, however deeply nested.
     */
    public function testRulesThatDependOnTheTimeOfDayOrWeekdayAreNeverPublic(): void
    {
        $this->ruleLoader->method('load')->willReturn(new RuleCollection([
            $this->ruleWithPayload('rule-sunday', new AndRule([new OrRule([new WeekdayRule(Rule::OPERATOR_EQ, 7)])])),
            $this->ruleWithPayload('rule-happy-hour', new TimeRangeRule()),
            $this->rule('rule-public', true),
        ]));

        $this->assertSame(['rule-public'], $this->resolver()->getGuestRuleIds('sc-1', 'curr-eur'));
    }

    /**
     * A dated sale is public while it runs and not before or after: the
     * boundary task re-syncs its products when it starts and ends.
     */
    public function testADatedRuleIsPublicOnlyWhileItsRangeRuns(): void
    {
        $this->ruleLoader->method('load')->willReturn(new RuleCollection([
            $this->ruleWithPayload('rule-running', new AndRule([new DateRangeRule(new \DateTime('-1 hour'), new \DateTime('+1 hour'), true)])),
            $this->ruleWithPayload('rule-ended', new AndRule([new DateRangeRule(new \DateTime('-2 hours'), new \DateTime('-1 hour'), true)])),
            $this->ruleWithPayload('rule-future', new AndRule([new DateRangeRule(new \DateTime('+1 hour'), new \DateTime('+2 hours'), true)])),
        ]));

        $this->assertSame(['rule-running'], $this->resolver()->getGuestRuleIds('sc-1', 'curr-eur'));
    }

    /**
     * The cache never outlives the next date boundary of any rule, so a sync
     * right after a sale starts or ends already sees the new state.
     */
    public function testTheCacheExpiresAtTheNextDateBoundary(): void
    {
        $this->ruleLoader->method('load')->willReturn(new RuleCollection([
            $this->ruleWithPayload('rule-soon', new AndRule([new DateRangeRule(new \DateTime('-1 hour'), new \DateTime('+1 second'), true)])),
        ]));
        $this->contextFactory->expects($this->exactly(2))->method('create');

        $resolver = $this->resolver();
        $this->assertSame(['rule-soon'], $resolver->getGuestRuleIds('sc-1', 'curr-eur'));
        sleep(2);
        $this->assertSame([], $resolver->getGuestRuleIds('sc-1', 'curr-eur'));
    }

    public function testDateBoundariesFollowDateRangeRuleWithoutUseTime(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $this->assertSame(
                [strtotime('2026-11-01 00:00:00 UTC'), strtotime('2026-11-04 00:00:00 UTC')],
                GuestRuleResolver::rangeBoundaries('2026-11-01T15:30:00+00:00', '2026-11-03T08:00:00+00:00', false),
            );
            $this->assertSame(
                [strtotime('2026-11-01 15:30:00 UTC'), strtotime('2026-11-03 08:00:00 UTC')],
                GuestRuleResolver::rangeBoundaries('2026-11-01T15:30:00+00:00', '2026-11-03T08:00:00+00:00', true),
            );
            $this->assertSame([], GuestRuleResolver::rangeBoundaries(null, 'not a date', true));
        } finally {
            date_default_timezone_set($tz);
        }
    }

    public function testRuleWithoutPayloadIsSkipped(): void
    {
        $rule = new RuleEntity();
        $rule->setId('rule-unindexed');
        $rule->setUniqueIdentifier('rule-unindexed');
        $this->ruleLoader->method('load')->willReturn(new RuleCollection([$rule]));

        $this->assertSame([], $this->resolver()->getGuestRuleIds('sc-1', null));
    }

    public function testContextFailureMeansNoRules(): void
    {
        $factory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('sales channel not found'));
        $this->ruleLoader->expects($this->never())->method('load');

        $resolver = new GuestRuleResolver($factory, $this->ruleLoader, new NullLogger());

        $this->assertSame([], $resolver->getGuestRuleIds('sc-missing', 'curr-eur'));
    }

    public function testCurrencyIsPassedToTheGuestContext(): void
    {
        $this->ruleLoader->method('load')->willReturn(new RuleCollection());
        $this->contextFactory->expects($this->once())
            ->method('create')
            ->with($this->isType('string'), 'sc-1', [SalesChannelContextService::CURRENCY_ID => 'curr-usd']);

        $this->resolver()->getGuestRuleIds('sc-1', 'curr-usd');
    }

    public function testResultIsCachedPerSalesChannelAndCurrency(): void
    {
        $this->ruleLoader->method('load')->willReturn(new RuleCollection([$this->rule('rule-public', true)]));
        $this->contextFactory->expects($this->exactly(2))->method('create');

        $resolver = $this->resolver();
        $resolver->getGuestRuleIds('sc-1', 'curr-eur');
        $resolver->getGuestRuleIds('sc-1', 'curr-eur');
        $resolver->getGuestRuleIds('sc-1', 'curr-usd');
    }

    private function resolver(): GuestRuleResolver
    {
        return new GuestRuleResolver($this->contextFactory, $this->ruleLoader, new NullLogger());
    }

    private function ruleWithPayload(string $id, Rule $payload): RuleEntity
    {
        $rule = new RuleEntity();
        $rule->setId($id);
        $rule->setUniqueIdentifier($id);
        $rule->setPayload($payload);

        return $rule;
    }

    private function rule(string $id, bool|\Throwable $match): RuleEntity
    {
        $payload = $this->createMock(Rule::class);
        if ($match instanceof \Throwable) {
            $payload->method('match')->willThrowException($match);
        } else {
            $payload->method('match')->with($this->isInstanceOf(RuleScope::class))->willReturn($match);
        }

        $rule = new RuleEntity();
        $rule->setId($id);
        $rule->setUniqueIdentifier($id);
        $rule->setPayload($payload);

        return $rule;
    }
}
