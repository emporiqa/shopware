<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Controller\Admin;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\FullSyncMessage;
use Emporiqa\ShopwarePlugin\Service\ChannelResolverInterface;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\ProductFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Emporiqa\ShopwarePlugin\Service\WebhookClientInterface;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\LandingPage\LandingPageCollection;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateCollection;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class SyncController extends AbstractController
{
    /** Prefix for per-session guard rows holding batch stats for the driven admin bulk sync. */
    private const SYNC_SESSION_PREFIX = 'EmporiqaIntegration.syncSession.';

    /** Index of active guard-row keys, SystemConfigService has no wildcard read. */
    private const SYNC_SESSION_INDEX_KEY = 'EmporiqaIntegration.syncSession.index';

    /** Prune guard rows older than this (seconds). */
    private const SYNC_SESSION_TTL_SECONDS = 86400;

    private const VALID_ENTITIES = ['products', 'pages'];

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<LandingPageCollection> $landingPageRepository
     * @param EntityRepository<CategoryCollection> $categoryRepository
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     * @param EntityRepository<PropertyGroupCollection> $propertyGroupRepository
     * @param EntityRepository<StateMachineStateCollection> $stateMachineStateRepository
     */
    public function __construct(
        private readonly SyncServiceInterface $syncService,
        private readonly WebhookClientInterface $webhookClient,
        private readonly ConfigServiceInterface $configService,
        private readonly ChannelResolverInterface $channelResolver,
        private readonly ProductFormatterInterface $productFormatter,
        private readonly CmsPageFormatterInterface $cmsPageFormatter,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $landingPageRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $stateMachineStateRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/api/_action/emporiqa/sync', name: 'api.action.emporiqa.sync', methods: ['POST'])]
    public function sync(Request $request): JsonResponse
    {
        if (!$this->configService->isConfigured()) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Plugin is not configured. Set the Store ID and Webhook Secret first.'],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $body = json_decode($request->getContent(), true);
            $entity = (\is_array($body) && isset($body['entity']) && \is_string($body['entity']))
                ? $body['entity']
                : 'all';

            $this->messageBus->dispatch(new FullSyncMessage($entity));

            return new JsonResponse([
                'success' => true,
                'queued' => true,
                'message' => 'Sync has been queued and will run in the background.',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Failed to queue sync: ' . $e->getMessage()],
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Start a driven admin bulk-sync: counts items per requested entity and,
     * for entities with items, opens a sync.start session on Emporiqa plus a
     * server-side guard row so subsequent sync-batch/sync-complete calls
     * cannot be spoofed by the driving JavaScript alone.
     */
    #[Route(path: '/api/_action/emporiqa/sync-init', name: 'api.action.emporiqa.sync-init', methods: ['POST'])]
    public function syncInit(Request $request): JsonResponse
    {
        if (!$this->configService->isConfigured()) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Plugin is not configured. Set the Store ID and Webhook Secret first.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $body = json_decode($request->getContent(), true);
        $requested = (\is_array($body) && isset($body['entities']) && \is_array($body['entities']))
            ? $body['entities']
            : [];

        $entities = array_values(array_intersect(self::VALID_ENTITIES, $requested));

        if (empty($entities)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No valid entities requested.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $this->pruneSyncSessions();

            $sessions = [];
            $batchSize = $this->configService->getBatchSize();
            foreach ($entities as $entity) {
                $total = $this->syncService->countItems($entity);
                if ($total <= 0) {
                    continue;
                }

                $sessionId = 'shopware-' . $entity . '-' . Uuid::randomHex();

                if (!$this->webhookClient->startSyncSession($sessionId, $entity)) {
                    $error = "Failed to start {$entity} sync session.";
                    $detail = $this->webhookClient->getLastError();
                    if ($detail !== null && $detail !== '') {
                        $error .= ' ' . $detail;
                    }

                    return new JsonResponse(['success' => false, 'error' => $error], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
                }

                $this->writeSyncSessionGuard($sessionId, $entity, $batchSize);

                $sessions[] = ['entity' => $entity, 'sessionId' => $sessionId, 'total' => $total];
            }

            return new JsonResponse([
                'success' => true,
                'batchSize' => $batchSize,
                'sessions' => $sessions,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to initialize sync: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Process a single page of a driven admin bulk-sync session. Presumes
     * failure before running the batch (so a fatal error mid-batch still
     * poisons the session guard) and reverses the mark on success.
     */
    #[Route(path: '/api/_action/emporiqa/sync-batch', name: 'api.action.emporiqa.sync-batch', methods: ['POST'])]
    public function syncBatch(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $entity = (\is_array($body) && isset($body['entity']) && \is_string($body['entity'])) ? $body['entity'] : '';
        $sessionId = (\is_array($body) && isset($body['sessionId']) && \is_string($body['sessionId'])) ? $body['sessionId'] : '';
        $page = (\is_array($body) && isset($body['page'])) ? max(1, (int) $body['page']) : 1;

        $guard = $this->readSyncSessionGuard($sessionId);
        if ($guard === null || $guard['entity'] !== $entity) {
            return new JsonResponse(['success' => false, 'error' => 'Unknown sync session.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $guard['errors']++;
        $this->writeSyncSessionGuardData($sessionId, $guard);

        try {
            $result = $this->syncService->syncBatch($entity, $page, $sessionId, $guard['batchSize']);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'processed' => 0,
                'events' => 0,
                'error' => 'Batch failed: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (!empty($result['success'])) {
            $guard = $this->readSyncSessionGuard($sessionId);
            if ($guard !== null) {
                $guard['errors'] = max(0, $guard['errors'] - 1);
                $guard['synced'] += $result['processed'];
                $this->writeSyncSessionGuardData($sessionId, $guard);
            }
        }

        return new JsonResponse($result);
    }

    /**
     * Complete a driven admin bulk-sync session. sync.complete tells the
     * Emporiqa backend to delete every item it did not see during the
     * session, so completion is refused unless every batch succeeded and at
     * least one item was synced.
     */
    #[Route(path: '/api/_action/emporiqa/sync-complete', name: 'api.action.emporiqa.sync-complete', methods: ['POST'])]
    public function syncComplete(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $entity = (\is_array($body) && isset($body['entity']) && \is_string($body['entity'])) ? $body['entity'] : '';
        $sessionId = (\is_array($body) && isset($body['sessionId']) && \is_string($body['sessionId'])) ? $body['sessionId'] : '';

        $guard = $this->readSyncSessionGuard($sessionId);
        if ($guard === null || $guard['entity'] !== $entity) {
            return new JsonResponse(['success' => false, 'error' => 'Unknown sync session.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($guard['errors'] > 0) {
            $message = sprintf(
                'Not completing the %s sync session: %d batch(es) failed. Resolve the errors and run the sync again, completing now would delete the failed items from Emporiqa.',
                $entity,
                $guard['errors'],
            );

            return new JsonResponse(['success' => false, 'skipped' => true, 'error' => $message]);
        }

        if ($guard['synced'] < 1) {
            $message = sprintf('Not completing the %s sync session: no items were synced.', $entity);

            return new JsonResponse(['success' => false, 'skipped' => true, 'error' => $message]);
        }

        if (!$this->webhookClient->completeSyncSession($sessionId, $entity)) {
            $error = 'Failed to complete sync session.';
            $detail = $this->webhookClient->getLastError();
            if ($detail !== null && $detail !== '') {
                $error .= ' ' . $detail;
            }

            return new JsonResponse(['success' => false, 'error' => $error], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        $synced = $guard['synced'];
        $this->deleteSyncSessionGuard($sessionId);

        return new JsonResponse(['success' => true, 'synced' => $synced]);
    }

    #[Route(path: '/api/_action/emporiqa/test-connection', name: 'api.action.emporiqa.test-connection', methods: ['POST'])]
    public function testConnection(Context $context): JsonResponse
    {
        try {
            $events = $this->buildTestEvents($context);
            $result = $this->webhookClient->testConnection($events);

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/api/_action/emporiqa/sync-overview', name: 'api.action.emporiqa.sync-overview', methods: ['POST'])]
    public function syncOverview(Context $context): JsonResponse
    {
        try {
            $productCriteria = new Criteria();
            $productCriteria->addFilter(new EqualsFilter('active', true));
            $productCriteria->addFilter(new EqualsFilter('parentId', null));
            $productCriteria->setLimit(1);
            $productCriteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
            $productCount = $this->productRepository->search($productCriteria, $context)->getTotal();

            $pageCriteria = new Criteria();
            $pageCriteria->addFilter(new EqualsFilter('active', true));
            $pageCriteria->setLimit(1);
            $pageCriteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
            $landingPageCount = $this->landingPageRepository->search($pageCriteria, $context)->getTotal();

            $shopPageCriteria = new Criteria();
            $shopPageCriteria->addFilter(new EqualsFilter('active', true));
            $shopPageCriteria->addFilter(new EqualsFilter('type', 'page'));
            $shopPageCriteria->addFilter(new EqualsFilter('cmsPage.type', 'page'));
            $shopPageCriteria->setLimit(1);
            $shopPageCriteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
            $shopPageCount = $this->categoryRepository->search($shopPageCriteria, $context)->getTotal();

            $pageCount = $landingPageCount + $shopPageCount;

            $channelContexts = $this->syncService->buildChannelContexts();
            $channelMapping = $this->channelResolver->getMapping();

            // Build sales channel ID → name lookup and group by Emporiqa channel key
            $scCriteria = new Criteria();
            $scCriteria->addFilter(new EqualsFilter('active', true));
            $scCriteria->addAssociation('domains');
            $salesChannels = $this->salesChannelRepository->search($scCriteria, $context);
            $scByChannel = [];
            foreach ($salesChannels as $sc) {
                /** @var SalesChannelEntity $sc */
                // Skip Headless API channels
                if ($sc->getTypeId() === Defaults::SALES_CHANNEL_TYPE_API) {
                    continue;
                }
                $domains = $sc->getDomains();
                if ($domains === null || $domains->count() === 0) {
                    continue;
                }
                $key = $channelMapping[$sc->getId()] ?? '';
                $scByChannel[$key][] = $sc->getTranslation('name') ?? $sc->getId();
            }

            $channelSummary = [];
            foreach ($channelContexts as $channelKey => $contexts) {
                $languages = array_unique(array_column($contexts, 'languageCode'));
                $currencies = array_unique(array_column($contexts, 'currencyIso'));
                $channelSummary[] = [
                    'channelKey' => $channelKey,
                    'languages' => array_values($languages),
                    'currencies' => array_values($currencies),
                    'salesChannels' => $scByChannel[$channelKey] ?? [],
                ];
            }

            $allLanguages = [];
            foreach ($channelContexts as $contexts) {
                foreach ($contexts as $ctx) {
                    $allLanguages[$ctx['languageCode']] = true;
                }
            }

            return new JsonResponse([
                'productCount' => $productCount,
                'pageCount' => $pageCount,
                'languages' => array_keys($allLanguages),
                'channels' => $channelSummary,
                'isConfigured' => $this->configService->isConfigured(),
                'syncProducts' => $this->configService->isSyncProductsEnabled(),
                'syncPages' => $this->configService->isSyncPagesEnabled(),
                'cartEnabled' => $this->configService->isCartEnabled(),
                'orderTrackingEnabled' => $this->configService->isOrderTrackingEnabled(),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to load overview: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/api/_action/emporiqa/data-preview', name: 'api.action.emporiqa.data-preview', methods: ['POST'])]
    public function dataPreview(Context $context): JsonResponse
    {
        if (!$this->configService->isConfigured()) {
            return new JsonResponse([
                'product' => null,
                'page' => null,
                'error' => 'Plugin is not configured. Set the Store ID and Webhook Secret first.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $channelContexts = $this->syncService->buildChannelContexts();

            if (empty($channelContexts)) {
                return new JsonResponse([
                    'product' => null,
                    'page' => null,
                    'error' => 'No languages configured.',
                ]);
            }

            $productPreview = null;
            $productCriteria = new Criteria();
            $productCriteria->addFilter(new EqualsFilter('active', true));
            $productCriteria->addFilter(new EqualsFilter('parentId', null));
            $productCriteria->addAssociation('translations');
            $productCriteria->addAssociation('categories.translations');
            $productCriteria->addAssociation('manufacturer.translations');
            $productCriteria->addAssociation('media.media');
            $productCriteria->addAssociation('cover.media');
            $productCriteria->addAssociation('seoUrls');
            $productCriteria->addAssociation('properties.group.translations');
            $productCriteria->addAssociation('properties.translations');
            $productCriteria->addAssociation('visibilities');
            $productCriteria->setLimit(1);

            $childrenCriteria = $productCriteria->getAssociation('children');
            $childrenCriteria->setLimit(5);
            $childrenCriteria->addAssociation('options.group.translations');
            $childrenCriteria->addAssociation('options.translations');
            $childrenCriteria->addAssociation('translations');
            $childrenCriteria->addAssociation('media.media');
            $childrenCriteria->addAssociation('cover.media');
            $childrenCriteria->addAssociation('seoUrls');

            $product = $this->productRepository->search($productCriteria, $context)->getEntities()->first();
            if ($product instanceof ProductEntity) {
                $formatted = $this->productFormatter->formatProduct($product, $channelContexts);
                $productPreview = $formatted[0] ?? null;
            }

            $pagePreview = null;
            $pageCriteria = new Criteria();
            $pageCriteria->addFilter(new EqualsFilter('active', true));
            $pageCriteria->addAssociation('cmsPage.sections.blocks.slots.translations');
            $pageCriteria->addAssociation('translations');
            $pageCriteria->addAssociation('salesChannels');
            $pageCriteria->addAssociation('seoUrls');
            $pageCriteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
            $pageCriteria->setLimit(5);

            // The first landing page may not be reachable in a synced channel, try a few
            $landingPages = $this->landingPageRepository->search($pageCriteria, $context)->getEntities();
            foreach ($landingPages as $landingPage) {
                $pagePreview = $this->cmsPageFormatter->formatLandingPage($landingPage, $channelContexts);
                if ($pagePreview !== null) {
                    break;
                }
            }

            return new JsonResponse([
                'product' => $productPreview,
                'page' => $pagePreview,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'product' => null,
                'page' => null,
                'error' => 'Failed to load preview: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/api/_action/emporiqa/sales-channels', name: 'api.action.emporiqa.sales-channels', methods: ['POST'])]
    public function salesChannels(Context $context): JsonResponse
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addAssociation('domains.language.locale');
            $criteria->addAssociation('domains.currency');

            $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();
            $result = [];

            /** @var SalesChannelEntity $salesChannel */
            foreach ($salesChannels as $salesChannel) {
                // Headless channels are never synced, so they offer no languages
                if ($salesChannel->getTypeId() === Defaults::SALES_CHANNEL_TYPE_API) {
                    continue;
                }

                $domains = [];
                $domainCollection = $salesChannel->getDomains();
                if ($domainCollection !== null) {
                    foreach ($domainCollection as $domain) {
                        $language = $domain->getLanguage();
                        $currency = $domain->getCurrency();
                        $languageName = '';
                        $languageCode = '';
                        if ($language !== null) {
                            $locale = $language->getLocale();
                            $languageName = $language->getName();
                            if ($locale !== null) {
                                $languageCode = $locale->getCode();
                            }
                        }

                        $domains[] = [
                            'url' => $domain->getUrl(),
                            'language' => $languageName,
                            'languageCode' => $languageCode,
                            'languageId' => $domain->getLanguageId(),
                            'currency' => $currency !== null ? $currency->getIsoCode() : '',
                            'currencyId' => $domain->getCurrencyId(),
                        ];
                    }
                }

                $result[] = [
                    'id' => $salesChannel->getId(),
                    'name' => $salesChannel->getName() ?? $salesChannel->getId(),
                    'domains' => $domains,
                ];
            }

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Failed to load sales channels: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/api/_action/emporiqa/property-groups', name: 'api.action.emporiqa.property-groups', methods: ['POST'])]
    public function propertyGroups(Context $context): JsonResponse
    {
        try {
            $criteria = new Criteria();
            $criteria->setLimit(500);

            $groups = $this->propertyGroupRepository->search($criteria, $context);
            $result = [];

            foreach ($groups as $group) {
                /** @var PropertyGroupEntity $group */
                $result[] = [
                    'id' => $group->getId(),
                    'name' => $group->getTranslation('name') ?? $group->getName() ?? $group->getId(),
                ];
            }

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Failed to load property groups: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/api/_action/emporiqa/order-states', name: 'api.action.emporiqa.order-states', methods: ['POST'])]
    public function orderStates(Context $context): JsonResponse
    {
        try {
            $criteria = new Criteria();
            $criteria->addAssociation('stateMachine');
            $criteria->setLimit(500);

            $states = $this->stateMachineStateRepository->search($criteria, $context);
            $orderStates = [];
            $transactionStates = [];

            foreach ($states as $state) {
                /** @var StateMachineStateEntity $state */
                $stateMachine = $state->getStateMachine();
                $technicalName = $stateMachine !== null ? $stateMachine->getTechnicalName() : '';
                $stateName = $state->getTranslation('name') ?? $state->getName();
                $stateTechnicalName = $state->getTechnicalName();

                $entry = [
                    'technicalName' => $stateTechnicalName,
                    'name' => $stateName,
                    'stateMachine' => $technicalName,
                ];

                if ($technicalName === 'order.state') {
                    $orderStates[] = $entry;
                } elseif ($technicalName === 'order_transaction.state') {
                    $transactionStates[] = $entry;
                }
            }

            return new JsonResponse([
                'orderStates' => $orderStates,
                'transactionStates' => $transactionStates,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Failed to load order states: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    private function buildTestEvents(Context $context): array
    {
        if (!$this->configService->isConfigured()) {
            return [];
        }

        try {
            $channelContexts = $this->syncService->buildChannelContexts();
            if (empty($channelContexts)) {
                return [];
            }

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addFilter(new EqualsFilter('parentId', null));
            $criteria->addAssociation('translations');
            $criteria->addAssociation('categories.translations');
            $criteria->addAssociation('manufacturer.translations');
            $criteria->addAssociation('media.media');
            $criteria->addAssociation('cover.media');
            $criteria->addAssociation('seoUrls');
            $criteria->addAssociation('properties.group.translations');
            $criteria->addAssociation('properties.translations');
            $criteria->addAssociation('visibilities');
            $criteria->addAssociation('prices');
            $criteria->setLimit(5);

            $childrenCriteria = $criteria->getAssociation('children');
            $childrenCriteria->setLimit(5);
            $childrenCriteria->addAssociation('options.group.translations');
            $childrenCriteria->addAssociation('options.translations');
            $childrenCriteria->addAssociation('translations');
            $childrenCriteria->addAssociation('media.media');
            $childrenCriteria->addAssociation('cover.media');
            $childrenCriteria->addAssociation('prices');
            $childrenCriteria->addAssociation('seoUrls');

            $products = $this->productRepository->search($criteria, $context)->getEntities();

            // Prefer a product that has children (variants)
            $product = null;
            foreach ($products as $candidate) {
                if ($candidate->getChildren() && $candidate->getChildren()->count() > 0) {
                    $product = $candidate;
                    break;
                }
            }

            if ($product === null) {
                $product = $products->first();
            }

            if (!$product instanceof ProductEntity) {
                return [];
            }

            $formatted = $this->productFormatter->formatProduct($product, $channelContexts);

            return array_map(
                fn (array $item) => ['type' => 'product.created', 'data' => $item],
                $formatted,
            );
        } catch (\Throwable) {
            return [];
        }
    }

    #[Route(path: '/api/_action/emporiqa/save-settings', name: 'api.action.emporiqa.save-settings', methods: ['POST'])]
    public function saveSettings(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!\is_array($data)) {
                return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
            }

            $prefix = 'EmporiqaIntegration.config.';
            $allowedKeys = [
                'channelMapping', 'brandAttribute', 'orderCompletedStates',
                'storeId', 'webhookSecret', 'webhookUrl',
                'syncProducts', 'syncPages', 'batchSize', 'enabledLanguages',
            ];

            $booleanKeys = [
                'syncProducts', 'syncPages',
            ];
            $integerKeys = ['batchSize'];

            // Validate everything first, so a rejected value never leaves a partial save behind.
            $values = [];
            foreach ($allowedKeys as $key) {
                if (!\array_key_exists($key, $data)) {
                    continue;
                }
                $value = $data[$key];

                if ($key === 'webhookUrl' && \is_string($value) && $value !== '' && !str_starts_with($value, 'https://')) {
                    return new JsonResponse(['error' => 'Webhook URL must use HTTPS.'], JsonResponse::HTTP_BAD_REQUEST);
                }

                if ($key === 'enabledLanguages') {
                    if (!\is_array($value)) {
                        return new JsonResponse(['error' => 'Enabled languages must be a list.'], JsonResponse::HTTP_BAD_REQUEST);
                    }
                    $value = array_values(array_unique(array_filter(
                        $value,
                        static fn ($code): bool => \is_string($code) && $code !== '',
                    )));

                    $unknown = array_values(array_diff($value, $this->storefrontLocaleCodes($context)));
                    if ($unknown !== []) {
                        return new JsonResponse(
                            ['error' => 'Unknown language codes: ' . implode(', ', $unknown)],
                            JsonResponse::HTTP_BAD_REQUEST,
                        );
                    }
                }

                if (\is_array($value)) {
                    $value = json_encode($value);
                }
                if (\in_array($key, $booleanKeys, true)) {
                    $value = (bool) $value;
                } elseif (\in_array($key, $integerKeys, true)) {
                    $value = (int) $value;
                }
                $values[$key] = $value;
            }

            foreach ($values as $key => $value) {
                $this->systemConfigService->set($prefix . $key, $value);
            }

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Failed to save settings: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Locale codes of all storefront sales channel domains, the values the
     * enabled languages setting is matched against.
     *
     * @return list<string>
     */
    private function storefrontLocaleCodes(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('domains.language.locale');

        $codes = [];
        $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();
        foreach ($salesChannels as $salesChannel) {
            if ($salesChannel->getTypeId() === Defaults::SALES_CHANNEL_TYPE_API) {
                continue;
            }
            foreach ($salesChannel->getDomains() ?? [] as $domain) {
                $code = $domain->getLanguage()?->getLocale()?->getCode();
                if ($code !== null && $code !== '') {
                    $codes[$code] = $code;
                }
            }
        }

        return array_values($codes);
    }

    // -------------------------------------------------------------------------
    // Driven admin bulk-sync, per-session guard rows in SystemConfig.
    //
    // Each sync-init/sync-batch/sync-complete request is a separate HTTP call,
    // so the driving admin JavaScript cannot be trusted alone to decide when
    // it's safe to complete a session. One guard row per session (rather than
    // a shared map) avoids read-modify-write races between concurrently
    // running syncs. SystemConfigService has no wildcard read, so a small
    // index of active guard-row keys is maintained alongside them.
    // -------------------------------------------------------------------------

    /**
     * @return array{entity: string, errors: int, synced: int, createdAt: int, batchSize: int|null}|null
     */
    private function readSyncSessionGuard(string $sessionId): ?array
    {
        if ($sessionId === '') {
            return null;
        }

        $raw = $this->systemConfigService->get(self::SYNC_SESSION_PREFIX . md5($sessionId));
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!\is_array($data) || !isset($data['entity'], $data['errors'], $data['synced'], $data['createdAt'])) {
            return null;
        }

        return [
            'entity' => (string) $data['entity'],
            'errors' => (int) $data['errors'],
            'synced' => (int) $data['synced'],
            'createdAt' => (int) $data['createdAt'],
            'batchSize' => isset($data['batchSize']) ? (int) $data['batchSize'] : null,
        ];
    }

    private function writeSyncSessionGuard(string $sessionId, string $entity, ?int $batchSize = null): void
    {
        $this->writeSyncSessionGuardData($sessionId, [
            'entity' => $entity,
            'errors' => 0,
            'synced' => 0,
            'batchSize' => $batchSize,
            'createdAt' => time(),
        ]);

        $this->addSyncSessionToIndex($sessionId);
    }

    /**
     * @param array{entity: string, errors: int, synced: int, createdAt: int} $guard
     */
    private function writeSyncSessionGuardData(string $sessionId, array $guard): void
    {
        $this->systemConfigService->set(
            self::SYNC_SESSION_PREFIX . md5($sessionId),
            json_encode($guard),
        );
    }

    private function deleteSyncSessionGuard(string $sessionId): void
    {
        $this->systemConfigService->delete(self::SYNC_SESSION_PREFIX . md5($sessionId));
        $this->removeSyncSessionFromIndex($sessionId);
    }

    /**
     * @return array<string, string> Map of md5(sessionId) => sessionId
     */
    private function readSyncSessionIndex(): array
    {
        $raw = $this->systemConfigService->get(self::SYNC_SESSION_INDEX_KEY);
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, string> $index
     */
    private function writeSyncSessionIndex(array $index): void
    {
        $this->systemConfigService->set(self::SYNC_SESSION_INDEX_KEY, json_encode($index));
    }

    private function addSyncSessionToIndex(string $sessionId): void
    {
        $index = $this->readSyncSessionIndex();
        $index[md5($sessionId)] = $sessionId;
        $this->writeSyncSessionIndex($index);
    }

    private function removeSyncSessionFromIndex(string $sessionId): void
    {
        $index = $this->readSyncSessionIndex();
        unset($index[md5($sessionId)]);
        $this->writeSyncSessionIndex($index);
    }

    /**
     * Drop guard rows (and their index entries) older than SYNC_SESSION_TTL_SECONDS —
     * abandoned or cancelled syncs would otherwise accumulate rows forever.
     */
    private function pruneSyncSessions(): void
    {
        $index = $this->readSyncSessionIndex();
        if (empty($index)) {
            return;
        }

        $now = time();
        $changed = false;

        foreach ($index as $hash => $sessionId) {
            $guard = $this->readSyncSessionGuard($sessionId);
            if ($guard === null || ($now - $guard['createdAt']) > self::SYNC_SESSION_TTL_SECONDS) {
                $this->systemConfigService->delete(self::SYNC_SESSION_PREFIX . $hash);
                unset($index[$hash]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->writeSyncSessionIndex($index);
        }
    }
}
