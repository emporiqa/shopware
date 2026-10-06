<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Emporiqa\ShopwarePlugin\Event\PostSyncEvent;
use Emporiqa\ShopwarePlugin\Event\PreSyncEvent;
use Emporiqa\ShopwarePlugin\Exception\RateLimitException;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\LandingPage\LandingPageCollection;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

class SyncService implements SyncServiceInterface, ResetInterface
{
    /** Cap on events per HTTP request sent to the webhook API. */
    private const EVENTS_PER_REQUEST = 50;

    /** @var array<string, array<int, array<string, string>>>|null */
    private ?array $cachedChannelContexts = null;

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<LandingPageCollection> $landingPageRepository
     * @param EntityRepository<CategoryCollection> $categoryRepository
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly WebhookClientInterface $webhookClient,
        private readonly ProductFormatterInterface $productFormatter,
        private readonly CmsPageFormatterInterface $cmsPageFormatter,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $landingPageRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ChannelResolverInterface $channelResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sync products using consolidated format (single session, all channels/languages).
     */
    public function syncProducts(?callable $progressCallback = null, bool $dryRun = false): array
    {
        $channelContexts = $this->buildChannelContexts();
        $batchSize = $this->config->getBatchSize();
        $totalEvents = 0;
        $totalProducts = 0;
        $errors = [];

        if (empty($channelContexts)) {
            return [
                'success' => false,
                'products' => 0,
                'events' => 0,
                'errors' => ['No sales channels or languages resolved.'],
            ];
        }

        $sessionId = 'shopware-products-' . Uuid::randomHex();

        $preSyncEvent = new PreSyncEvent('products', $sessionId, $channelContexts);
        $this->eventDispatcher->dispatch($preSyncEvent);
        $channelContexts = $preSyncEvent->getChannelContexts();

        if (!$dryRun) {
            if (!$this->webhookClient->startSyncSession($sessionId, 'products')) {
                $errors[] = 'Failed to start products sync session.';

                return ['success' => false, 'products' => 0, 'events' => 0, 'errors' => $errors];
            }
        }

        $context = SystemContext::create();
        $afterId = '';
        $offset = 0;

        while (true) {
            $criteria = self::keyset($this->buildProductCriteria(), $afterId, $batchSize);

            $products = $this->productRepository->search($criteria, $context)->getEntities();

            if ($products->count() === 0) {
                break;
            }

            [$events, $count] = $this->formatProductEvents($products, $channelContexts, $sessionId);
            $totalProducts += $count;

            $afterId = (string) $products->last()?->getId();
            $offset += $products->count();

            if (!$dryRun && !empty($events)) {
                $rateLimited = $this->sendEventsInChunks($events, $offset, $sessionId, 'product', 'product sync', $errors);
                if ($rateLimited) {
                    break;
                }
            }

            $totalEvents += \count($events);

            if ($progressCallback !== null) {
                $progressCallback($totalProducts, \count($events));
            }
        }

        if (!$dryRun) {
            if ($totalProducts === 0) {
                // Protects against a local bug (e.g. a bad filter) silently
                // telling Emporiqa the entire remote catalog is gone.
                $errors[] = 'Not completing the products sync session: no items were synced.';
                $this->logger->warning('[Emporiqa] Skipping sync session completion, zero items synced.', [
                    'sessionId' => $sessionId,
                ]);
            } elseif (!empty($errors)) {
                $this->logger->warning('[Emporiqa] Skipping sync session completion due to batch errors.', [
                    'sessionId' => $sessionId,
                    'errors' => $errors,
                ]);
            } elseif (!$this->webhookClient->completeSyncSession($sessionId, 'products')) {
                $errors[] = 'Failed to complete products sync session.';
            }
        }

        $result = [
            'success' => empty($errors),
            'products' => $totalProducts,
            'events' => $totalEvents,
            'errors' => $errors,
        ];

        $this->eventDispatcher->dispatch(new PostSyncEvent('products', $sessionId, $result));

        return $result;
    }

    /**
     * Sync landing pages using consolidated format.
     */
    public function syncPages(?callable $progressCallback = null, bool $dryRun = false): array
    {
        $channelContexts = $this->buildChannelContexts();
        $batchSize = $this->config->getBatchSize();
        $totalEvents = 0;
        $totalPages = 0;
        $errors = [];

        if (empty($channelContexts)) {
            return [
                'success' => false,
                'pages' => 0,
                'events' => 0,
                'errors' => ['No sales channels or languages resolved.'],
            ];
        }

        $sessionId = 'shopware-pages-' . Uuid::randomHex();

        $preSyncEvent = new PreSyncEvent('pages', $sessionId, $channelContexts);
        $this->eventDispatcher->dispatch($preSyncEvent);
        $channelContexts = $preSyncEvent->getChannelContexts();

        if (!$dryRun) {
            if (!$this->webhookClient->startSyncSession($sessionId, 'pages')) {
                $errors[] = 'Failed to start pages sync session.';

                return ['success' => false, 'pages' => 0, 'events' => 0, 'errors' => $errors];
            }
        }

        $context = SystemContext::create();
        $afterId = '';
        $offset = 0;

        while (true) {
            $criteria = self::keyset($this->buildLandingPageCriteria(), $afterId, $batchSize);

            $landingPages = $this->landingPageRepository->search($criteria, $context)->getEntities();

            if ($landingPages->count() === 0) {
                break;
            }

            [$events, $count] = $this->formatLandingPageEvents($landingPages, $channelContexts, $sessionId);
            $totalPages += $count;

            $afterId = (string) $landingPages->last()?->getId();
            $offset += $landingPages->count();

            if (!$dryRun && !empty($events)) {
                $rateLimited = $this->sendEventsInChunks($events, $offset, $sessionId, 'page', 'page sync', $errors);
                if ($rateLimited) {
                    break;
                }
            }

            $totalEvents += \count($events);

            if ($progressCallback !== null) {
                $progressCallback($totalPages, \count($events));
            }
        }

        // Sync shop pages (categories of type 'page' with a shop or landing page layout)
        $afterId = '';
        $offset = 0;
        while (true) {
            $criteria = self::keyset($this->buildShopPageCriteria(), $afterId, $batchSize);

            $shopPages = $this->categoryRepository->search($criteria, $context)->getEntities();

            if ($shopPages->count() === 0) {
                break;
            }

            [$events, $count] = $this->formatShopPageEvents($shopPages, $channelContexts, $sessionId);
            $totalPages += $count;

            $afterId = (string) $shopPages->last()?->getId();
            $offset += $shopPages->count();

            if (!$dryRun && !empty($events)) {
                $rateLimited = $this->sendEventsInChunks($events, $offset, $sessionId, 'shop page', 'shop page sync', $errors);
                if ($rateLimited) {
                    break;
                }
            }

            $totalEvents += \count($events);

            if ($progressCallback !== null) {
                $progressCallback($totalPages, \count($events));
            }
        }

        if (!$dryRun) {
            if ($totalPages === 0) {
                // Protects against a local bug (e.g. a bad filter) silently
                // telling Emporiqa the entire remote catalog is gone.
                $errors[] = 'Not completing the pages sync session: no items were synced.';
                $this->logger->warning('[Emporiqa] Skipping sync session completion, zero items synced.', [
                    'sessionId' => $sessionId,
                ]);
            } elseif (!empty($errors)) {
                $this->logger->warning('[Emporiqa] Skipping sync session completion due to batch errors.', [
                    'sessionId' => $sessionId,
                    'errors' => $errors,
                ]);
            } elseif (!$this->webhookClient->completeSyncSession($sessionId, 'pages')) {
                $errors[] = 'Failed to complete pages sync session.';
            }
        }

        $result = [
            'success' => empty($errors),
            'pages' => $totalPages,
            'events' => $totalEvents,
            'errors' => $errors,
        ];

        $this->eventDispatcher->dispatch(new PostSyncEvent('pages', $sessionId, $result));

        return $result;
    }

    /**
     * @return array{success: bool, products: int, pages: int, events: int, errors: string[]}
     */
    public function syncAll(?callable $progressCallback = null, bool $dryRun = false): array
    {
        $productResult = $this->syncProducts($progressCallback, $dryRun);
        $pageResult = $this->syncPages($progressCallback, $dryRun);

        return [
            'success' => $productResult['success'] && $pageResult['success'],
            'products' => $productResult['products'],
            'pages' => $pageResult['pages'],
            'events' => $productResult['events'] + $pageResult['events'],
            'errors' => array_merge($productResult['errors'], $pageResult['errors']),
        ];
    }

    public function resyncProducts(array $productIds): int
    {
        $channelContexts = $this->buildChannelContexts();
        if ($channelContexts === [] || $productIds === []) {
            return 0;
        }

        $sent = 0;
        $errors = [];
        $afterId = '';
        while (true) {
            $criteria = $this->buildProductCriteria();
            $criteria->setIds($productIds);
            $products = $this->productRepository->search(self::keyset($criteria, $afterId, $this->config->getBatchSize()), SystemContext::create())->getEntities();
            if ($products->count() === 0) {
                break;
            }
            $afterId = (string) $products->last()?->getId();

            $events = [];
            foreach ($products as $product) {
                foreach ($this->productFormatter->formatProduct($product, $channelContexts) as $item) {
                    $events[] = ['type' => 'product.updated', 'data' => $item];
                }
            }
            if ($events !== [] && $this->sendEventsInChunks($events, $sent, '', 'product', 'product re-sync', $errors)) {
                break;
            }
            $sent += $products->count();
        }
        if ($errors !== []) {
            $this->logger->warning('[Emporiqa] Product re-sync had errors.', ['errors' => $errors]);
        }

        return $sent;
    }

    /**
     * Send events to the webhook API in bounded-size chunks so a single HTTP
     * request never carries more than EVENTS_PER_REQUEST events. A failed
     * chunk is recorded as an error for the batch offset; a rate limit stops
     * the whole sync, signalled to the caller via the return value so the
     * outer while loop can break.
     *
     * @param array<int, array{type: string, data: array<string, mixed>}> $events
     * @param list<string> $errors
     */
    private function sendEventsInChunks(
        array $events,
        int|string $offset,
        string $sessionId,
        string $batchLabel,
        string $rateLimitContext,
        array &$errors,
    ): bool {
        foreach (array_chunk($events, self::EVENTS_PER_REQUEST) as $chunk) {
            try {
                if (!$this->webhookClient->sendBatchEvents($chunk)) {
                    // A keyset cursor (an id, or '' for the first page) means nothing to a merchant.
                    $error = \is_int($offset)
                        ? "Failed to send {$batchLabel} batch at offset {$offset}."
                        : "Failed to send {$batchLabel} batch.";
                    $detail = $this->webhookClient->getLastError();
                    if ($detail !== null && $detail !== '') {
                        $error .= ' ' . $detail;
                    }
                    $errors[] = $error;
                }
            } catch (RateLimitException $e) {
                $this->logger->warning("[Emporiqa] Rate limited during {$rateLimitContext}, stopping.", [
                    'sessionId' => $sessionId,
                    'offset' => $offset,
                ]);
                $errors[] = 'Rate limited by Emporiqa API. Try again later.';

                return true;
            }
        }

        return false;
    }

    public function countItems(string $entity): int
    {
        $context = SystemContext::create();

        if ($entity === 'products') {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addFilter(new EqualsFilter('parentId', null));
            $criteria->setLimit(1);
            $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

            return $this->productRepository->search($criteria, $context)->getTotal();
        }

        if ($entity === 'pages') {
            return $this->countLandingPages($context) + $this->countShopPages($context);
        }

        return 0;
    }

    public function syncBatch(string $entity, string $cursor, string $sessionId, ?int $batchSize = null): array
    {
        // Pin the page size for the whole session (passed from the sync-init
        // snapshot), so a merchant changing batchSize mid-sync changes nothing.
        $batchSize ??= $this->config->getBatchSize();

        if ($entity === 'products') {
            return $this->syncProductBatch($cursor, $sessionId, $batchSize);
        }

        if ($entity === 'pages') {
            return $this->syncPageBatch($cursor, $sessionId, $batchSize);
        }

        return ['success' => false, 'processed' => 0, 'events' => 0, 'error' => 'Unknown entity.', 'nextCursor' => null];
    }

    /**
     * Page by id (keyset), never OFFSET: an item that drops out of the
     * filter mid-sync (deactivated, deleted) would shift every later page by
     * one, skip a live item, and sync.complete would then delete it.
     */
    private static function keyset(Criteria $criteria, string $afterId, int $limit): Criteria
    {
        if ($afterId !== '') {
            // Binary, as stored: the DAL passes a RangeFilter value through unconverted.
            $criteria->addFilter(new RangeFilter('id', [RangeFilter::GT => Uuid::fromHexToBytes($afterId)]));
        }
        $criteria->setLimit($limit);

        return $criteria;
    }

    private function countLandingPages(Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit(1);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $this->landingPageRepository->search($criteria, $context)->getTotal();
    }

    private function countShopPages(Context $context): int
    {
        // A non-root category needs an assigned CMS layout to have any content to
        // sync - Shopware's system-default-layout fallback is not replicated. Tree
        // roots are exempt: formatShopPage() always syncs the navigation root (the
        // home page) even without one, footer/service roots resolve to null there.
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('type', 'page'));
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new NotFilter(MultiFilter::CONNECTION_AND, [new EqualsFilter('cmsPageId', null)]),
            new EqualsFilter('parentId', null),
        ]));
        $criteria->setLimit(1);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $this->categoryRepository->search($criteria, $context)->getTotal();
    }

    /**
     * One batch of the products driven sync: the next $batchSize parents
     * after the id in $cursor ('' to start). nextCursor is null after the last.
     *
     * @return array{success: bool, processed: int, events: int, error?: string, nextCursor: ?string}
     */
    private function syncProductBatch(string $cursor, string $sessionId, int $batchSize): array
    {
        $channelContexts = $this->buildChannelContexts();
        if (empty($channelContexts)) {
            return ['success' => true, 'processed' => 0, 'events' => 0, 'nextCursor' => null];
        }

        $criteria = self::keyset($this->buildProductCriteria(), $cursor, $batchSize);
        $products = $this->productRepository->search($criteria, SystemContext::create())->getEntities();

        [$events, $processed] = $this->formatProductEvents($products, $channelContexts, $sessionId);

        $result = $this->sendBatchResult($events, $processed, $cursor, $sessionId, 'product', 'product sync');
        $result['nextCursor'] = $products->count() < $batchSize ? null : (string) $products->last()?->getId();

        return $result;
    }

    /**
     * One batch of the pages driven sync: landing pages first, then shop-page
     * categories. The cursor names the phase and the last id
     * ("landing:<id>", "shop:<id>"; '' starts with landing pages).
     *
     * @return array{success: bool, processed: int, events: int, error?: string, nextCursor: ?string}
     */
    private function syncPageBatch(string $cursor, string $sessionId, int $batchSize): array
    {
        $channelContexts = $this->buildChannelContexts();
        if (empty($channelContexts)) {
            return ['success' => true, 'processed' => 0, 'events' => 0, 'nextCursor' => null];
        }

        [$phase, $afterId] = str_contains($cursor, ':') ? explode(':', $cursor, 2) : ['landing', ''];
        $context = SystemContext::create();

        if ($phase === 'landing') {
            $criteria = self::keyset($this->buildLandingPageCriteria(), $afterId, $batchSize);
            $pages = $this->landingPageRepository->search($criteria, $context)->getEntities();
            [$events, $processed] = $this->formatLandingPageEvents($pages, $channelContexts, $sessionId);
            $next = $pages->count() < $batchSize ? 'shop:' : 'landing:' . $pages->last()?->getId();
        } else {
            $criteria = self::keyset($this->buildShopPageCriteria(), $afterId, $batchSize);
            $pages = $this->categoryRepository->search($criteria, $context)->getEntities();
            [$events, $processed] = $this->formatShopPageEvents($pages, $channelContexts, $sessionId);
            $next = $pages->count() < $batchSize ? null : 'shop:' . $pages->last()?->getId();
        }

        $result = $this->sendBatchResult($events, $processed, $cursor, $sessionId, 'page', 'page sync');
        $result['nextCursor'] = $next;

        return $result;
    }

    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $events
     *
     * @return array{success: bool, processed: int, events: int, error?: string}
     */
    private function sendBatchResult(
        array $events,
        int $processed,
        int|string $offset,
        string $sessionId,
        string $batchLabel,
        string $rateLimitContext,
    ): array {
        $errors = [];
        if (!empty($events)) {
            $this->sendEventsInChunks($events, $offset, $sessionId, $batchLabel, $rateLimitContext, $errors);
        }

        $result = [
            'success' => empty($errors),
            'processed' => $processed,
            'events' => \count($events),
        ];

        if (!empty($errors)) {
            // Every chunk of a batch fails the same way when Emporiqa is down: say it once.
            $result['error'] = implode(' ', array_unique($errors));
        }

        return $result;
    }

    private function buildProductCriteria(): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('parentId', null));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $criteria->addAssociation('children.options.group');
        $criteria->addAssociation('children.media.media');
        $criteria->addAssociation('children.cover.media');
        $criteria->addAssociation('children.seoUrls');
        $criteria->addAssociation('children.translations');
        $criteria->addAssociation('children.options.group.translations');
        $criteria->addAssociation('children.options.translations');
        $criteria->addAssociation('translations');
        $criteria->addAssociation('categories.translations');
        $criteria->addAssociation('manufacturer.translations');
        $criteria->addAssociation('media.media');
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('properties.group.translations');
        $criteria->addAssociation('properties.translations');
        $criteria->addAssociation('seoUrls');
        $criteria->addAssociation('visibilities');
        // Advanced (rule) prices, needed to build tier_prices. Without these
        // associations getPrices() is null and tier_prices is always empty.
        $criteria->addAssociation('prices');
        $criteria->addAssociation('children.prices');

        return $criteria;
    }

    private function buildLandingPageCriteria(): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $criteria->addAssociation('cmsPage.sections.blocks.slots.translations');
        $criteria->addAssociation('translations');
        $criteria->addAssociation('salesChannels');
        $criteria->getAssociation('seoUrls')->addFilter(new EqualsFilter('routeName', 'frontend.landing.page'), new EqualsFilter('isCanonical', true), new EqualsFilter('isDeleted', false));

        return $criteria;
    }

    private function buildShopPageCriteria(): Criteria
    {
        // No cmsPage.type restriction: a listing-layout category can carry its own
        // text or FAQ blocks above/below the product grid, and formatShopPage()
        // gates on whether it actually has content. A non-root category still
        // needs an assigned layout (cmsPageId not null) though - without one there
        // is nothing to render, and a category's plain description field alone
        // (the fallback formatShopPage() otherwise reaches for) is too common and
        // often too thin to justify a page on every one of them. Tree roots are
        // exempt from that requirement: formatShopPage() always syncs the
        // navigation root (the home page) even without a layout of its own;
        // footer/service roots resolve to null there regardless.
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('type', 'page'));
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new NotFilter(MultiFilter::CONNECTION_AND, [new EqualsFilter('cmsPageId', null)]),
            new EqualsFilter('parentId', null),
        ]));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $criteria->addAssociation('cmsPage.sections.blocks.slots.translations');
        $criteria->addAssociation('translations');
        $criteria->getAssociation('seoUrls')->addFilter(new EqualsFilter('routeName', 'frontend.navigation.page'), new EqualsFilter('isCanonical', true), new EqualsFilter('isDeleted', false));

        return $criteria;
    }

    /**
     * @param iterable<ProductEntity> $products
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array{0: array<int, array{type: string, data: array<string, mixed>}>, 1: int}
     */
    private function formatProductEvents(iterable $products, array $channelContexts, string $sessionId): array
    {
        $events = [];
        $processed = 0;

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $formatted = $this->productFormatter->formatProduct($product, $channelContexts, $sessionId);

            foreach ($formatted as $item) {
                $events[] = ['type' => 'product.updated', 'data' => $item];
            }
            $processed++;
        }

        return [$events, $processed];
    }

    /**
     * @param iterable<LandingPageEntity> $landingPages
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array{0: array<int, array{type: string, data: array<string, mixed>}>, 1: int}
     */
    private function formatLandingPageEvents(iterable $landingPages, array $channelContexts, string $sessionId): array
    {
        $events = [];
        $processed = 0;

        /** @var LandingPageEntity $landingPage */
        foreach ($landingPages as $landingPage) {
            $formatted = $this->cmsPageFormatter->formatLandingPage($landingPage, $channelContexts, $sessionId);

            if ($formatted !== null) {
                $events[] = ['type' => 'page.updated', 'data' => $formatted];
                $processed++;
            }
        }

        return [$events, $processed];
    }

    /**
     * @param iterable<CategoryEntity> $shopPages
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array{0: array<int, array{type: string, data: array<string, mixed>}>, 1: int}
     */
    private function formatShopPageEvents(iterable $shopPages, array $channelContexts, string $sessionId): array
    {
        $events = [];
        $processed = 0;

        /** @var CategoryEntity $category */
        foreach ($shopPages as $category) {
            $formatted = $this->cmsPageFormatter->formatShopPage($category, $channelContexts, $sessionId);

            if ($formatted !== null) {
                $events[] = ['type' => 'page.updated', 'data' => $formatted];
                $processed++;
            }
        }

        return [$events, $processed];
    }

    public function reset(): void
    {
        $this->cachedChannelContexts = null;
    }

    /**
     * Build channel contexts from active sales channels and channel mapping config.
     *
     * Returns an array grouped by Emporiqa channel key, where each entry contains
     * domain information (URL, language code, currency ISO, exchange factor and item
     * rounding as strings, sales channel ID, language ID)
     * and the sales channel's tree roots (navigation, footer, service category IDs).
     * Sales channels and domain languages excluded by the enabled sales channels /
     * enabled languages settings are skipped.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    public function buildChannelContexts(): array
    {
        if ($this->cachedChannelContexts !== null) {
            return $this->cachedChannelContexts;
        }

        $context = SystemContext::create();
        $channelMapping = $this->channelResolver->getMapping();
        $enabledLanguages = $this->config->getEnabledLanguages();
        $enabledSalesChannels = $this->config->getEnabledSalesChannels();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        // Deterministic order, so the same domain wins the link on every run
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $criteria->getAssociation('domains')->addSorting(new FieldSorting('url', FieldSorting::ASCENDING));
        $criteria->addAssociation('domains.language.locale');
        $criteria->addAssociation('domains.currency');

        $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();

        // Collect all contexts keyed by channel key
        $raw = [];

        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            // Headless API channels have no product addresses to link to; see ChannelResolver::autoDetect()
            if ($salesChannel->getTypeId() === Defaults::SALES_CHANNEL_TYPE_API) {
                continue;
            }

            if ($enabledSalesChannels !== [] && !\in_array($salesChannel->getId(), $enabledSalesChannels, true)) {
                continue;
            }

            $emporiqaChannelKey = $channelMapping[$salesChannel->getId()] ?? '';

            $domains = $salesChannel->getDomains();
            if ($domains === null) {
                continue;
            }

            foreach ($domains as $domain) {
                $language = $domain->getLanguage();
                if ($language === null) {
                    continue;
                }

                $locale = $language->getLocale();
                if ($locale === null) {
                    continue;
                }

                $langCode = $locale->getCode();
                if ($enabledLanguages !== [] && !\in_array($langCode, $enabledLanguages, true)) {
                    continue;
                }

                $currency = $domain->getCurrency();
                $currencyIso = $currency !== null ? $currency->getIsoCode() : 'EUR';

                $entry = [
                    'domainUrl' => rtrim($domain->getUrl(), '/'),
                    'languageCode' => $langCode,
                    'currencyIso' => $currencyIso,
                    'currencyId' => $domain->getCurrencyId() ?? '',
                    'currencyFactor' => (string) ($currency !== null ? $currency->getFactor() : 1.0),
                    'currencyDecimals' => (string) ($currency?->getItemRounding()->getDecimals() ?? 2),
                    'currencyInterval' => (string) ($currency?->getItemRounding()->getInterval() ?? 0.01),
                    'salesChannelId' => $salesChannel->getId(),
                    'languageId' => $language->getId(),
                    'navigationCategoryId' => $salesChannel->getNavigationCategoryId(),
                    'footerCategoryId' => $salesChannel->getFooterCategoryId() ?? '',
                    'serviceCategoryId' => $salesChannel->getServiceCategoryId() ?? '',
                ];

                // Deduplicate by sales channel+language+currency per channel key
                // Keep first entry; only replace to upgrade HTTP → HTTPS
                $dedupeKey = $salesChannel->getId() . '|' . $langCode . '|' . $currencyIso;
                if (!isset($raw[$emporiqaChannelKey][$dedupeKey])) {
                    $raw[$emporiqaChannelKey][$dedupeKey] = $entry;
                } elseif (
                    !str_starts_with($raw[$emporiqaChannelKey][$dedupeKey]['domainUrl'], 'https://')
                    && str_starts_with($entry['domainUrl'], 'https://')
                ) {
                    $raw[$emporiqaChannelKey][$dedupeKey] = $entry;
                }
            }
        }

        // Convert to indexed arrays
        $result = [];
        foreach ($raw as $channelKey => $entries) {
            $result[$channelKey] = array_values($entries);
        }

        $this->cachedChannelContexts = $result;

        return $result;
    }
}
