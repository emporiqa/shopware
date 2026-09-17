<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Controller;

use Emporiqa\ShopwarePlugin\Controller\Admin\SyncController;
use Emporiqa\ShopwarePlugin\Service\ChannelResolverInterface;
use Emporiqa\ShopwarePlugin\Service\CmsPageFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\ProductFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Emporiqa\ShopwarePlugin\Service\WebhookClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Emporiqa\ShopwarePlugin\Tests\Support\EntityCollectionHelper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

class SyncControllerTest extends TestCase
{
    use EntityCollectionHelper;

    /** @var array<string, mixed> In-memory backing store for the SystemConfigService mock */
    private array $configStore = [];

    private SyncServiceInterface&MockObject $syncService;
    private WebhookClientInterface&MockObject $webhookClient;
    private ConfigServiceInterface&MockObject $configService;
    private SystemConfigService&MockObject $systemConfigService;
    private EntityRepository&MockObject $salesChannelRepository;
    private SyncController $controller;

    protected function setUp(): void
    {
        $this->configStore = [];

        $this->syncService = $this->createMock(SyncServiceInterface::class);
        $this->webhookClient = $this->createMock(WebhookClientInterface::class);
        $this->configService = $this->createMock(ConfigServiceInterface::class);
        $this->configService->method('isConfigured')->willReturn(true);
        $this->configService->method('getBatchSize')->willReturn(50);

        $channelResolver = $this->createMock(ChannelResolverInterface::class);
        $productFormatter = $this->createMock(ProductFormatterInterface::class);
        $cmsPageFormatter = $this->createMock(CmsPageFormatterInterface::class);
        $productRepository = $this->createMock(EntityRepository::class);
        $landingPageRepository = $this->createMock(EntityRepository::class);
        $categoryRepository = $this->createMock(EntityRepository::class);
        $this->salesChannelRepository = $this->createMock(EntityRepository::class);
        $this->mockStorefrontLanguages(['en-GB', 'de-DE']);
        $propertyGroupRepository = $this->createMock(EntityRepository::class);
        $stateMachineStateRepository = $this->createMock(EntityRepository::class);
        $messageBus = $this->createMock(MessageBusInterface::class);

        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->systemConfigService
            ->method('get')
            ->willReturnCallback(fn (string $key) => $this->configStore[$key] ?? null);
        $this->systemConfigService
            ->method('set')
            ->willReturnCallback(function (string $key, $value): void {
                $this->configStore[$key] = $value;
            });
        $this->systemConfigService
            ->method('delete')
            ->willReturnCallback(function (string $key): void {
                unset($this->configStore[$key]);
            });

        $this->controller = new SyncController(
            $this->syncService,
            $this->webhookClient,
            $this->configService,
            $channelResolver,
            $productFormatter,
            $cmsPageFormatter,
            $productRepository,
            $landingPageRepository,
            $categoryRepository,
            $this->salesChannelRepository,
            $propertyGroupRepository,
            $stateMachineStateRepository,
            $this->systemConfigService,
            $messageBus,
        );
    }

    /**
     * @param list<string> $localeCodes
     */
    private function mockStorefrontLanguages(array $localeCodes): void
    {
        $domains = [];
        foreach ($localeCodes as $code) {
            $locale = new LocaleEntity();
            $locale->setId('locale-' . $code);
            $locale->setCode($code);
            $language = new LanguageEntity();
            $language->setId('lang-' . $code);
            $language->setLocale($locale);
            $domain = new SalesChannelDomainEntity();
            $domain->setId('domain-' . $code);
            $domain->setUrl('https://shop.example.com/' . $code);
            $domain->setLanguageId($language->getId());
            $domain->setLanguage($language);
            $domains[] = $domain;
        }

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-1');
        $salesChannel->setTypeId(Defaults::SALES_CHANNEL_TYPE_STOREFRONT);
        $salesChannel->setDomains(new SalesChannelDomainCollection($domains));

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(self::entityCollection($salesChannel));
        $this->salesChannelRepository->method('search')->willReturn($result);
    }

    private function jsonRequest(array $body): Request
    {
        return new Request([], [], [], [], [], [], json_encode($body));
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    // --- save-settings ---

    public function testSaveSettingsStoresEnabledLanguagesAsCleanJsonList(): void
    {
        $response = $this->controller->saveSettings(
            $this->jsonRequest(['enabledLanguages' => ['en-GB', 'de-DE', 'en-GB', '', 5]]),
            Context::createDefaultContext(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('["en-GB","de-DE"]', $this->configStore['EmporiqaIntegration.config.enabledLanguages']);
    }

    public function testSaveSettingsRejectsNonListEnabledLanguages(): void
    {
        $response = $this->controller->saveSettings($this->jsonRequest(['enabledLanguages' => 'en-GB']), Context::createDefaultContext());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayNotHasKey('EmporiqaIntegration.config.enabledLanguages', $this->configStore);
    }

    public function testSaveSettingsRejectsLanguageCodesWithoutStorefrontDomain(): void
    {
        $response = $this->controller->saveSettings(
            $this->jsonRequest(['enabledLanguages' => ['en-GB', 'fr-FR'], 'storeId' => 'new-store']),
            Context::createDefaultContext(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('fr-FR', $this->decode($response)['error']);
        // Nothing is written when any value is rejected
        $this->assertArrayNotHasKey('EmporiqaIntegration.config.storeId', $this->configStore);
    }

    public function testSaveSettingsStoresEnabledSalesChannels(): void
    {
        $response = $this->controller->saveSettings(
            $this->jsonRequest(['enabledSalesChannels' => ['sc-1', 'sc-1', '']]),
            Context::createDefaultContext(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('["sc-1"]', $this->configStore['EmporiqaIntegration.config.enabledSalesChannels']);
    }

    public function testSaveSettingsRejectsUnknownSalesChannels(): void
    {
        $response = $this->controller->saveSettings(
            $this->jsonRequest(['enabledSalesChannels' => ['sc-1', 'sc-ghost']]),
            Context::createDefaultContext(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('sc-ghost', $this->decode($response)['error']);
        $this->assertArrayNotHasKey('EmporiqaIntegration.config.enabledSalesChannels', $this->configStore);
    }

    public function testSaveSettingsWritesNothingWhenWebhookUrlIsRejected(): void
    {
        $response = $this->controller->saveSettings(
            $this->jsonRequest(['storeId' => 'new-store', 'webhookUrl' => 'http://insecure.example.com/']),
            Context::createDefaultContext(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayNotHasKey('EmporiqaIntegration.config.storeId', $this->configStore);
    }

    // --- sync-init ---

    public function testSyncInitReturns400WhenNotConfigured(): void
    {
        $this->configService = $this->createMock(ConfigServiceInterface::class);
        $this->configService->method('isConfigured')->willReturn(false);

        $controller = $this->rebuildControllerWithConfigService($this->configService);

        $response = $controller->syncInit($this->jsonRequest(['entities' => ['products']]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['success']);
    }

    public function testSyncInitReturns400ForNoValidEntities(): void
    {
        $response = $this->controller->syncInit($this->jsonRequest(['entities' => ['bogus']]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['success']);
    }

    public function testSyncInitStartsSessionsOnlyForEntitiesWithItems(): void
    {
        $this->syncService
            ->method('countItems')
            ->willReturnMap([
                ['products', 10],
                ['pages', 0],
            ]);
        $this->webhookClient->method('startSyncSession')->willReturn(true);

        $response = $this->controller->syncInit($this->jsonRequest(['entities' => ['products', 'pages']]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertTrue($data['success']);
        $this->assertSame(50, $data['batchSize']);
        $this->assertCount(1, $data['sessions']);
        $this->assertSame('products', $data['sessions'][0]['entity']);
        $this->assertSame(10, $data['sessions'][0]['total']);
        $this->assertStringStartsWith('shopware-products-', $data['sessions'][0]['sessionId']);

        // A guard row must have been persisted for the started session.
        $guardKey = 'EmporiqaIntegration.syncSession.' . md5($data['sessions'][0]['sessionId']);
        $this->assertArrayHasKey($guardKey, $this->configStore);
    }

    public function testSyncInitReturnsErrorWhenStartSyncSessionFails(): void
    {
        $this->syncService->method('countItems')->willReturn(5);
        $this->webhookClient->method('startSyncSession')->willReturn(false);
        $this->webhookClient->method('getLastError')->willReturn('Store not found');

        $response = $this->controller->syncInit($this->jsonRequest(['entities' => ['products']]));

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Store not found', $data['error']);
    }

    // --- sync-batch ---

    public function testSyncBatchReturns400ForUnknownSession(): void
    {
        $response = $this->controller->syncBatch($this->jsonRequest([
            'entity' => 'products',
            'sessionId' => 'does-not-exist',
            'page' => 1,
        ]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertFalse($data['success']);
        $this->assertSame('Unknown sync session.', $data['error']);
    }

    public function testSyncBatchReturns400WhenEntityDoesNotMatchGuard(): void
    {
        $sessionId = $this->seedGuard('products');

        $response = $this->controller->syncBatch($this->jsonRequest([
            'entity' => 'pages',
            'sessionId' => $sessionId,
            'page' => 1,
        ]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testSyncBatchPassesPinnedBatchSizeFromGuard(): void
    {
        $sessionId = $this->seedGuard('products', batchSize: 25);

        $this->syncService
            ->expects($this->once())
            ->method('syncBatch')
            ->with('products', 4, $sessionId, 25)
            ->willReturn(['success' => true, 'processed' => 25, 'events' => 25]);

        $response = $this->controller->syncBatch($this->jsonRequest([
            'entity' => 'products',
            'sessionId' => $sessionId,
            'page' => 4,
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSyncBatchUpdatesGuardOnSuccess(): void
    {
        $sessionId = $this->seedGuard('products');

        $this->syncService
            ->method('syncBatch')
            ->with('products', 2, $sessionId, null)
            ->willReturn(['success' => true, 'processed' => 7, 'events' => 7]);

        $response = $this->controller->syncBatch($this->jsonRequest([
            'entity' => 'products',
            'sessionId' => $sessionId,
            'page' => 2,
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertTrue($data['success']);
        $this->assertSame(7, $data['processed']);

        $guardKey = 'EmporiqaIntegration.syncSession.' . md5($sessionId);
        $guard = json_decode($this->configStore[$guardKey], true);
        $this->assertSame(0, $guard['errors']);
        $this->assertSame(7, $guard['synced']);
    }

    public function testSyncBatchLeavesErrorMarkedOnFailure(): void
    {
        $sessionId = $this->seedGuard('products');

        $this->syncService
            ->method('syncBatch')
            ->willReturn(['success' => false, 'processed' => 0, 'events' => 0, 'error' => 'boom']);

        $response = $this->controller->syncBatch($this->jsonRequest([
            'entity' => 'products',
            'sessionId' => $sessionId,
            'page' => 1,
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertFalse($data['success']);

        $guardKey = 'EmporiqaIntegration.syncSession.' . md5($sessionId);
        $guard = json_decode($this->configStore[$guardKey], true);
        // Presume-fail mark from before the batch ran is not reversed.
        $this->assertSame(1, $guard['errors']);
        $this->assertSame(0, $guard['synced']);
    }

    // --- sync-complete ---

    public function testSyncCompleteReturns400ForUnknownSession(): void
    {
        $response = $this->controller->syncComplete($this->jsonRequest([
            'entity' => 'products',
            'sessionId' => 'does-not-exist',
        ]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testSyncCompleteRefusesWhenGuardHasErrors(): void
    {
        $sessionId = $this->seedGuard('products', errors: 1, synced: 5);

        $this->webhookClient->expects($this->never())->method('completeSyncSession');

        $response = $this->controller->syncComplete($this->jsonRequest([
            'entity' => 'products',
            'sessionId' => $sessionId,
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertFalse($data['success']);
        $this->assertTrue($data['skipped']);
        $this->assertStringContainsString('batch(es) failed', $data['error']);

        // Guard row must survive a refused completion.
        $guardKey = 'EmporiqaIntegration.syncSession.' . md5($sessionId);
        $this->assertArrayHasKey($guardKey, $this->configStore);
    }

    public function testSyncCompleteRefusesWhenNothingWasSynced(): void
    {
        $sessionId = $this->seedGuard('products', errors: 0, synced: 0);

        $this->webhookClient->expects($this->never())->method('completeSyncSession');

        $response = $this->controller->syncComplete($this->jsonRequest([
            'entity' => 'products',
            'sessionId' => $sessionId,
        ]));

        $data = $this->decode($response);
        $this->assertFalse($data['success']);
        $this->assertTrue($data['skipped']);
        $this->assertStringContainsString('no items were synced', $data['error']);
    }

    public function testSyncCompleteSucceedsAndDeletesGuard(): void
    {
        $sessionId = $this->seedGuard('products', errors: 0, synced: 12);

        $this->webhookClient
            ->expects($this->once())
            ->method('completeSyncSession')
            ->with($sessionId, 'products')
            ->willReturn(true);

        $response = $this->controller->syncComplete($this->jsonRequest([
            'entity' => 'products',
            'sessionId' => $sessionId,
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertTrue($data['success']);
        $this->assertSame(12, $data['synced']);

        $guardKey = 'EmporiqaIntegration.syncSession.' . md5($sessionId);
        $this->assertArrayNotHasKey($guardKey, $this->configStore);
    }

    public function testSyncCompleteReturnsErrorWhenWebhookClientFails(): void
    {
        $sessionId = $this->seedGuard('products', errors: 0, synced: 3);

        $this->webhookClient->method('completeSyncSession')->willReturn(false);
        $this->webhookClient->method('getLastError')->willReturn('Timed out');

        $response = $this->controller->syncComplete($this->jsonRequest([
            'entity' => 'products',
            'sessionId' => $sessionId,
        ]));

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Timed out', $data['error']);

        // Guard must survive a failed completion so the session can be retried.
        $guardKey = 'EmporiqaIntegration.syncSession.' . md5($sessionId);
        $this->assertArrayHasKey($guardKey, $this->configStore);
    }

    /**
     * Seed a guard row (and its index entry) as if sync-init had run, and
     * return the generated session id.
     */
    private function seedGuard(string $entity, int $errors = 0, int $synced = 0, ?int $batchSize = null): string
    {
        $sessionId = 'shopware-' . $entity . '-' . bin2hex(random_bytes(8));
        $guardKey = 'EmporiqaIntegration.syncSession.' . md5($sessionId);

        $this->configStore[$guardKey] = json_encode([
            'entity' => $entity,
            'errors' => $errors,
            'synced' => $synced,
            'createdAt' => time(),
            'batchSize' => $batchSize,
        ]);

        $index = json_decode($this->configStore['EmporiqaIntegration.syncSession.index'] ?? '{}', true) ?: [];
        $index[md5($sessionId)] = $sessionId;
        $this->configStore['EmporiqaIntegration.syncSession.index'] = json_encode($index);

        return $sessionId;
    }

    private function rebuildControllerWithConfigService(ConfigServiceInterface&MockObject $configService): SyncController
    {
        return new SyncController(
            $this->syncService,
            $this->webhookClient,
            $configService,
            $this->createMock(ChannelResolverInterface::class),
            $this->createMock(ProductFormatterInterface::class),
            $this->createMock(CmsPageFormatterInterface::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->systemConfigService,
            $this->createMock(MessageBusInterface::class),
        );
    }
}
