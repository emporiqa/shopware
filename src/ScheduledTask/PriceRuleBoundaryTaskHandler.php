<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\ScheduledTask;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\Service\ActionGuard;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\GuestRuleResolver;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Finds the date-range conditions that started or ended since the last run
 * and re-syncs every product with advanced prices on those rules. Also the
 * plugin's one regular job, so it clears the action endpoints' expired
 * answers and counters (ActionGuard::purgeExpired) on a store with no calls.
 */
#[AsMessageHandler(handles: PriceRuleBoundaryTask::class)]
class PriceRuleBoundaryTaskHandler extends ScheduledTaskHandler
{
    public const CHECKED_AT_KEY = 'EmporiqaIntegration.config.priceRuleCheckedAt';

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfig,
        private readonly ConfigServiceInterface $config,
        private readonly SyncServiceInterface $syncService,
        private readonly ActionGuard $actionGuard,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $this->runAt(time());
    }

    /**
     * @return list<string> the parent product ids re-synced
     */
    public function runAt(int $now): array
    {
        try {
            $this->actionGuard->purgeExpired($now);
        } catch (\Throwable) {
            // Cleanup only; the price boundaries below still run.
        }

        $since = $this->systemConfig->getInt(self::CHECKED_AT_KEY);
        $this->systemConfig->set(self::CHECKED_AT_KEY, $now);
        if ($since <= 0) {
            $since = $now - PriceRuleBoundaryTask::getDefaultInterval();
        }

        if (!$this->config->isConfigured() || !$this->config->isSyncProductsEnabled()) {
            return [];
        }

        $ruleIds = [];
        $conditions = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`rule_id`)) AS `rule_id`, `value` FROM `rule_condition` WHERE `type` = :type',
            ['type' => 'dateRange'],
        );
        foreach ($conditions as $condition) {
            $value = json_decode((string) $condition['value'], true);
            if (!\is_array($value)) {
                continue;
            }
            $boundaries = GuestRuleResolver::rangeBoundaries($value['fromDate'] ?? null, $value['toDate'] ?? null, (bool) ($value['useTime'] ?? false));
            foreach ($boundaries as $boundary) {
                if ($boundary > $since && $boundary <= $now) {
                    $ruleIds[(string) $condition['rule_id']] = true;
                }
            }
        }
        if ($ruleIds === []) {
            return [];
        }

        $productIds = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(COALESCE(p.`parent_id`, p.`id`))) FROM `product_price` pp'
            . ' INNER JOIN `product` p ON p.`id` = pp.`product_id` AND p.`version_id` = pp.`product_version_id`'
            . ' WHERE pp.`rule_id` IN (:ruleIds) AND pp.`product_version_id` = :liveVersion',
            ['ruleIds' => Uuid::fromHexToBytesList(array_keys($ruleIds)), 'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ruleIds' => ArrayParameterType::BINARY],
        );
        $productIds = array_values(array_filter($productIds, 'is_string'));
        if ($productIds !== []) {
            $this->syncService->resyncProducts($productIds);
        }

        return $productIds;
    }
}
