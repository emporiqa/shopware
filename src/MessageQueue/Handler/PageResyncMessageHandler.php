<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\MessageQueue\Handler;

use Emporiqa\ShopwarePlugin\Event\PostPageFormatEvent;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\LandingPage\LandingPageCollection;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Formats changed pages and queues their webhooks. Runs in the worker so the
 * storefront CMS resolution never happens inside an entity write request.
 */
#[AsMessageHandler]
class PageResyncMessageHandler
{
    private const BATCH_SIZE = 50;

    /** @var array<string, array<int, array<string, string>>> */
    private array $channelContexts = [];

    /** @var array<string, true> */
    private array $createdIds = [];

    /** @var array<int, array{type: string, data: array<string, mixed>}> */
    private array $events = [];

    /** @var array{updated: int, deleted: int} */
    private array $stats = ['updated' => 0, 'deleted' => 0];

    /**
     * @param EntityRepository<LandingPageCollection> $landingPageRepository
     * @param EntityRepository<CategoryCollection> $categoryRepository
     */
    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly SyncServiceInterface $syncService,
        private readonly CmsPageFormatterInterface $cmsPageFormatter,
        private readonly EntityRepository $landingPageRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PageResyncMessage $message): void
    {
        if ($message->isEmpty() || !$this->config->isConfigured() || !$this->config->isSyncPagesEnabled()) {
            return;
        }

        $this->channelContexts = $this->syncService->buildChannelContexts();
        if (empty($this->channelContexts)) {
            return;
        }

        $this->createdIds = array_fill_keys($message->getCreatedIds(), true);
        $this->events = [];
        $this->stats = ['updated' => 0, 'deleted' => 0];
        $context = Context::createCLIContext();

        foreach (array_chunk($message->getLandingPageIds(), self::BATCH_SIZE) as $ids) {
            foreach ($this->loadLandingPages($this->landingPageCriteria(new Criteria($ids)), $context) as $landingPage) {
                $this->guarded(fn () => $this->syncLandingPage($landingPage), $landingPage->getId());
            }
            $this->flush();
        }

        $structural = array_fill_keys($message->getStructuralCategoryIds(), true);
        foreach (array_chunk($message->getCategoryIds(), self::BATCH_SIZE) as $ids) {
            // Most written categories are listing categories (and every category write
            // fans out to its subtree through the SEO URL listener): decide on a cheap
            // read first and load the CMS layout only for the shop pages.
            $shopPageIds = [];
            foreach ($this->loadCategories($this->categoryLookupCriteria(new Criteria($ids)), $context) as $category) {
                if ($this->needsFormatting($category, isset($structural[$category->getId()]))) {
                    $shopPageIds[] = $category->getId();
                }
            }

            if ($shopPageIds !== []) {
                foreach ($this->loadCategories($this->categoryCriteria(new Criteria($shopPageIds)), $context) as $category) {
                    $this->guarded(fn () => $this->syncCategory($category, isset($structural[$category->getId()])), $category->getId());
                }
            }
            $this->flush();
        }

        if ($message->getCmsPageIds() !== []) {
            $this->resyncLayouts($message->getCmsPageIds(), $context);
        }

        $this->flush();

        $this->logger->info('[Emporiqa] Page sync processed.', [
            'landingPages' => \count($message->getLandingPageIds()),
            'categories' => \count($message->getCategoryIds()),
            'layouts' => \count($message->getCmsPageIds()),
            'updated' => $this->stats['updated'],
            'deleted' => $this->stats['deleted'],
        ]);
    }

    private function syncLandingPage(LandingPageEntity $landingPage): void
    {
        $id = $landingPage->getId();

        if (!$landingPage->isActive()) {
            $this->delete($id);

            return;
        }

        $formatted = $this->cmsPageFormatter->formatLandingPage($landingPage, $this->channelContexts);
        if ($formatted === null) {
            // Not reachable in any synced sales channel, e.g. unassigned.
            $this->delete($id);

            return;
        }

        $postFormatEvent = new PostPageFormatEvent($landingPage, $formatted);
        $this->eventDispatcher->dispatch($postFormatEvent);

        $this->update($id, $postFormatEvent->getFormattedData());
    }

    /**
     * Cheap decision on a category without its CMS layout loaded: sends the deletes
     * that need no formatting and says whether the category must be formatted.
     */
    private function needsFormatting(CategoryEntity $category, bool $structuralChange): bool
    {
        $id = $category->getId();

        // Tree roots (navigation, footer, service) are not pages.
        if ($category->getParentId() === null) {
            return false;
        }

        if (!$this->isShopPage($category)) {
            // Only a change of type, layout, parent or active flag can turn a synced
            // shop page into a non-page; other writes (e.g. product assignments)
            // routinely hit listing categories that were never synced.
            if ($structuralChange) {
                $this->delete($id);
            }

            return false;
        }

        if (!$category->getActive()) {
            $this->delete($id);

            return false;
        }

        return true;
    }

    private function syncCategory(CategoryEntity $category, bool $structuralChange): void
    {
        $id = $category->getId();

        if (!$this->needsFormatting($category, $structuralChange)) {
            return;
        }

        $formatted = $this->cmsPageFormatter->formatShopPage($category, $this->channelContexts);
        if ($formatted === null) {
            $this->delete($id);

            return;
        }

        $this->update($id, $formatted);
    }

    /**
     * @param list<string> $cmsPageIds
     */
    private function resyncLayouts(array $cmsPageIds, Context $context): void
    {
        $offset = 0;
        do {
            $criteria = $this->landingPageCriteria(new Criteria())->setOffset($offset);
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addFilter(new EqualsAnyFilter('cmsPageId', $cmsPageIds));
            $landingPages = $this->loadLandingPages($criteria, $context);

            foreach ($landingPages as $landingPage) {
                $this->guarded(fn () => $this->syncLandingPage($landingPage), $landingPage->getId());
            }
            $this->flush();
            $offset += self::BATCH_SIZE;
        } while ($landingPages->count() === self::BATCH_SIZE);

        $offset = 0;
        do {
            $criteria = $this->categoryCriteria(new Criteria())->setOffset($offset);
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addFilter(new EqualsFilter('type', 'page'));
            $criteria->addFilter(new EqualsAnyFilter('cmsPageId', $cmsPageIds));
            $criteria->addFilter(new EqualsAnyFilter('cmsPage.type', CmsPageFormatterInterface::SHOP_PAGE_LAYOUT_TYPES));
            $criteria->addFilter(new NotFilter(MultiFilter::CONNECTION_AND, [new EqualsFilter('parentId', null)]));
            $categories = $this->loadCategories($criteria, $context);

            foreach ($categories as $category) {
                $this->guarded(fn () => $this->syncCategory($category, false), $category->getId());
            }
            $this->flush();
            $offset += self::BATCH_SIZE;
        } while ($categories->count() === self::BATCH_SIZE);
    }

    /**
     * @return EntityCollection<LandingPageEntity>
     */
    private function loadLandingPages(Criteria $criteria, Context $context): EntityCollection
    {
        return $this->landingPageRepository->search($criteria, $context)->getEntities();
    }

    /**
     * @return EntityCollection<CategoryEntity>
     */
    private function loadCategories(Criteria $criteria, Context $context): EntityCollection
    {
        return $this->categoryRepository->search($criteria, $context)->getEntities();
    }

    /**
     * One failing page must not abort the whole message (Messenger would retry it
     * and re-send the webhooks already flushed, then give up on the rest).
     */
    private function guarded(callable $sync, string $id): void
    {
        try {
            $sync();
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] Failed to format page for sync.', [
                'id' => $id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isShopPage(CategoryEntity $category): bool
    {
        $cmsPage = $category->getCmsPage();

        return $category->getType() === 'page'
            && $cmsPage !== null
            && \in_array($cmsPage->getType(), CmsPageFormatterInterface::SHOP_PAGE_LAYOUT_TYPES, true);
    }

    /**
     * @param array<string, mixed> $formatted
     */
    private function update(string $id, array $formatted): void
    {
        $this->events[] = [
            'type' => isset($this->createdIds[$id]) ? 'page.created' : 'page.updated',
            'data' => $formatted,
        ];
        $this->stats['updated']++;
    }

    private function delete(string $id): void
    {
        // A page that was just created has nothing to delete remotely.
        if (isset($this->createdIds[$id])) {
            return;
        }

        $deletes = $this->cmsPageFormatter->formatPageDelete($id);
        foreach ($deletes as $deleteData) {
            $this->events[] = ['type' => 'page.deleted', 'data' => $deleteData];
        }
        if ($deletes !== []) {
            $this->stats['deleted']++;
        }
    }

    private function flush(): void
    {
        foreach (array_chunk($this->events, self::BATCH_SIZE) as $events) {
            $this->messageBus->dispatch(new WebhookMessage($events));
        }
        $this->events = [];
    }

    private function landingPageCriteria(Criteria $criteria): Criteria
    {
        $criteria->setLimit(self::BATCH_SIZE);
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $criteria->addAssociation('cmsPage.sections.blocks.slots.translations');
        $criteria->addAssociation('translations');
        $criteria->addAssociation('salesChannels');
        $criteria->getAssociation('seoUrls')->addFilter(new EqualsFilter('routeName', 'frontend.landing.page'), new EqualsFilter('isCanonical', true), new EqualsFilter('isDeleted', false));

        return $criteria;
    }

    private function categoryLookupCriteria(Criteria $criteria): Criteria
    {
        $criteria->setLimit(self::BATCH_SIZE);
        $criteria->addAssociation('cmsPage');

        return $criteria;
    }

    private function categoryCriteria(Criteria $criteria): Criteria
    {
        $criteria->setLimit(self::BATCH_SIZE);
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $criteria->addAssociation('cmsPage.sections.blocks.slots.translations');
        $criteria->addAssociation('translations');
        $criteria->getAssociation('seoUrls')->addFilter(new EqualsFilter('routeName', 'frontend.navigation.page'), new EqualsFilter('isCanonical', true), new EqualsFilter('isDeleted', false));

        return $criteria;
    }
}
