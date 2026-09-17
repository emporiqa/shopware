<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\PageSyncRegistry;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Seo\Event\SeoUrlUpdateEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Re-syncs pages whose storefront URL was generated or changed, so the links
 * sent to Emporiqa follow: SEO URLs are written by the indexer (possibly from the
 * message queue, after the page write itself) and by the SEO URL settings.
 */
class SeoUrlSubscriber implements EventSubscriberInterface
{
    use EntityWriteEventTrait;

    private const ROUTE_LANDING_PAGE = 'frontend.landing.page';
    private const ROUTE_CATEGORY = 'frontend.navigation.page';

    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly PageSyncRegistry $registry,
        private readonly Connection $connection,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SeoUrlUpdateEvent::class => 'onSeoUrlUpdate',
            'seo_url.written' => 'onSeoUrlWritten',
        ];
    }

    /**
     * Fired by the SEO URL persister after generating URLs (indexer).
     */
    public function onSeoUrlUpdate(SeoUrlUpdateEvent $event): void
    {
        if (!$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        $landingPageIds = [];
        $categoryIds = [];
        foreach ($event->getSeoUrls() as $seoUrl) {
            $page = self::pageOfSeoUrlRow($seoUrl);
            if ($page === null) {
                continue;
            }

            if ($page['landingPage']) {
                $landingPageIds[$page['id']] = $page['id'];
            } else {
                $categoryIds[$page['id']] = $page['id'];
            }
        }

        $this->queue(array_values($landingPageIds), array_values($categoryIds));
    }

    /**
     * Landing page or category a canonical SEO URL row belongs to. The technical
     * path tells the entity type, route names are not part of the event.
     *
     * @return array{id: string, landingPage: bool}|null
     */
    private static function pageOfSeoUrlRow(mixed $row): ?array
    {
        if (!\is_array($row) || ($row['isDeleted'] ?? false) || !($row['isCanonical'] ?? true)) {
            return null;
        }

        $pathInfo = $row['pathInfo'] ?? '';
        if (!\is_string($pathInfo)) {
            return null;
        }

        // Cheap prefix test first: product URLs make up most rows of a bulk import
        $landingPage = str_starts_with($pathInfo, '/landingPage/');
        if (!$landingPage && !str_starts_with($pathInfo, '/navigation/')) {
            return null;
        }

        $foreignKey = $row['foreignKey'] ?? null;
        if (!\is_string($foreignKey) || !Uuid::isValid($foreignKey)) {
            return null;
        }

        return ['id' => $foreignKey, 'landingPage' => $landingPage];
    }

    /**
     * Fired when SEO URLs are edited through the DAL (Settings > SEO).
     */
    public function onSeoUrlWritten(EntityWrittenEvent $event): void
    {
        if ($event instanceof EntityDeletedEvent) {
            return;
        }

        if (!$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $seoUrlIds = [];
        foreach ($event->getWriteResults() as $result) {
            $seoUrlId = self::primaryKeyId($result->getPrimaryKey());
            if ($seoUrlId !== null && Uuid::isValid($seoUrlId)) {
                $seoUrlIds[] = $seoUrlId;
            }
        }

        if ($seoUrlIds === []) {
            return;
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT route_name, LOWER(HEX(foreign_key)) AS foreign_key FROM seo_url
                 WHERE id IN (:ids) AND is_canonical = 1 AND is_deleted = 0 AND route_name IN (:routes)',
                ['ids' => Uuid::fromHexToBytesList($seoUrlIds), 'routes' => [self::ROUTE_LANDING_PAGE, self::ROUTE_CATEGORY]],
                ['ids' => ArrayParameterType::BINARY, 'routes' => ArrayParameterType::STRING],
            );
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] Failed to resolve changed SEO URLs.', ['error' => $e->getMessage()]);

            return;
        }

        $landingPageIds = [];
        $categoryIds = [];
        foreach ($rows as $row) {
            $foreignKey = (string) $row['foreign_key'];
            if ($row['route_name'] === self::ROUTE_LANDING_PAGE) {
                $landingPageIds[$foreignKey] = $foreignKey;
            } else {
                $categoryIds[$foreignKey] = $foreignKey;
            }
        }

        $this->queue(array_values($landingPageIds), array_values($categoryIds));
    }

    /**
     * @param list<string> $landingPageIds
     * @param list<string> $categoryIds
     */
    private function queue(array $landingPageIds, array $categoryIds): void
    {
        // Ids already queued by the page subscribers in this request are skipped,
        // and the page subscribers skip ids claimed here (they run later).
        $landingPageIds = $this->registry->claim($landingPageIds);
        $categoryIds = $this->registry->claim($categoryIds);

        if ($landingPageIds === [] && $categoryIds === []) {
            return;
        }

        try {
            $this->messageBus->dispatch(new PageResyncMessage(landingPageIds: $landingPageIds, categoryIds: $categoryIds));
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] Failed to queue page sync after an SEO URL change.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
