<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Text edited in a Shopping Experiences layout changes every page that uses the
 * layout without writing those pages, so the affected pages are re-synced here.
 */
class CmsLayoutSubscriber implements EventSubscriberInterface
{
    use EntityWriteEventTrait;

    private const SECTION_PAGE_SQL = 'SELECT LOWER(HEX(cms_page_id)) FROM cms_section WHERE id IN (:ids) AND version_id = :live';

    private const BLOCK_PAGE_SQL = 'SELECT LOWER(HEX(section.cms_page_id)) FROM cms_block block
        INNER JOIN cms_section section ON section.id = block.cms_section_id AND section.version_id = block.version_id
        WHERE block.id IN (:ids) AND block.version_id = :live';

    private const SLOT_PAGE_SQL = 'SELECT LOWER(HEX(section.cms_page_id)) FROM cms_slot slot
        INNER JOIN cms_block block ON block.id = slot.cms_block_id AND block.version_id = slot.version_id
        INNER JOIN cms_section section ON section.id = block.cms_section_id AND section.version_id = block.version_id
        WHERE slot.id IN (:ids) AND slot.version_id = :live';

    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly Connection $connection,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After Shopware's entity indexing (priority 1000), like the page subscribers.
            EntityWrittenContainerEvent::class => ['onEntityWrittenContainer', -100],
        ];
    }

    public function onEntityWrittenContainer(EntityWrittenContainerEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $pageIds = $this->collectIds($event, 'cms_page');
        $sectionIds = $this->collectIds($event, 'cms_section');
        $blockIds = $this->collectIds($event, 'cms_block');
        $slotIds = array_values(array_unique(array_merge(
            $this->collectIds($event, 'cms_slot'),
            $this->collectIds($event, 'cms_slot_translation', 'cmsSlotId'),
        )));

        if ($pageIds === [] && $sectionIds === [] && $blockIds === [] && $slotIds === []) {
            return;
        }

        if (!$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        try {
            $pageIds = array_merge(
                $pageIds,
                $this->fetchPageIds(self::SECTION_PAGE_SQL, $sectionIds),
                $this->fetchPageIds(self::BLOCK_PAGE_SQL, $blockIds),
                $this->fetchPageIds(self::SLOT_PAGE_SQL, $slotIds),
            );
            $pageIds = array_values(array_unique($pageIds));

            if ($pageIds !== []) {
                $this->messageBus->dispatch(new PageResyncMessage($pageIds));
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] Failed to queue page re-sync after a layout change.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function collectIds(EntityWrittenContainerEvent $event, string $entityName, ?string $primaryKeyField = null): array
    {
        $ids = [];
        foreach ($event->getEvents() ?? [] as $nested) {
            $written = self::writtenEventOf($nested, $entityName);
            if ($written === null) {
                continue;
            }

            foreach ($written->getWriteResults() as $result) {
                $id = $this->extractId($result->getPrimaryKey(), $primaryKeyField);
                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Translation entities have a composite primary key, take the parent ID field from it.
     */
    private function extractId(mixed $primaryKey, ?string $primaryKeyField): ?string
    {
        if ($primaryKeyField !== null && \is_array($primaryKey)) {
            $primaryKey = $primaryKey[$primaryKeyField] ?? null;
        }

        return \is_string($primaryKey) && Uuid::isValid($primaryKey) ? $primaryKey : null;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function fetchPageIds(string $sql, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<string> $pageIds */
        $pageIds = $this->connection->fetchFirstColumn(
            $sql,
            ['ids' => Uuid::fromHexToBytesList($ids), 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY],
        );

        return $pageIds;
    }
}
