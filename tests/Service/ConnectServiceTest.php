<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\EmporiqaIntegration;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\ConnectService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConnectServiceTest extends TestCase
{
    private const PENDING_KEY = 'EmporiqaIntegration.config.connectPending';

    /** @var array<string, mixed> In-memory backing store for the SystemConfigService mock */
    private array $configStore = [];

    private SystemConfigService&MockObject $systemConfig;
    private ConfigServiceInterface&MockObject $config;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->configStore = [];

        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->systemConfig
            ->method('get')
            ->willReturnCallback(fn (string $key) => $this->configStore[$key] ?? null);
        $this->systemConfig
            ->method('set')
            ->willReturnCallback(function (string $key, $value): void {
                $this->configStore[$key] = $value;
            });
        $this->systemConfig
            ->method('delete')
            ->willReturnCallback(function (string $key): void {
                unset($this->configStore[$key]);
            });

        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->config->method('getWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/');

        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createService(?Client $client = null): ConnectService
    {
        return new ConnectService($this->systemConfig, $this->config, $this->logger, $client);
    }

    private function createClientWithMock(array $responses, array &$history = []): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    /**
     * @return array{state: string, verifier: string, origin: string, createdAt: int}
     */
    private function getPending(): array
    {
        $raw = $this->configStore[self::PENDING_KEY] ?? '';
        $this->assertIsString($raw);
        $pending = json_decode($raw, true);
        $this->assertIsArray($pending);

        return $pending;
    }

    // --- initiate() ---

    public function testInitiateBuildsCorrectStartUrl(): void
    {
        $service = $this->createService();

        $url = $service->initiate('https://myshop.example.com');

        $this->assertStringStartsWith('https://emporiqa.com/connect/start?', $url);

        // return_path must be URL-encoded in the raw query string
        $this->assertStringContainsString(
            'return_path=%2Fadmin%23%2Femporiqa%2Fconnect%2Fcallback',
            $url,
        );

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);

        $this->assertSame('shopware', $params['platform']);
        $this->assertSame('https://myshop.example.com', $params['shop_origin']);
        $this->assertSame('/admin#/emporiqa/connect/callback', $params['return_path']);
        $this->assertSame('S256', $params['code_challenge_method']);
        $this->assertSame(EmporiqaIntegration::PLUGIN_VERSION, $params['plugin_version']);

        $this->assertNotSame('', $params['state']);
        $this->assertLessThanOrEqual(128, \strlen($params['state']));

        // Challenge must be base64url(sha256(verifier)) of the persisted verifier
        $pending = $this->getPending();
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($expectedChallenge, $params['code_challenge']);
        $this->assertSame($pending['state'], $params['state']);
    }

    public function testInitiatePersistsPendingHandshake(): void
    {
        $service = $this->createService();

        $service->initiate('https://myshop.example.com');

        $pending = $this->getPending();
        $this->assertSame('https://myshop.example.com', $pending['origin']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{64}$/', $pending['verifier']);
        $this->assertGreaterThanOrEqual(43, \strlen($pending['verifier']));
        $this->assertLessThanOrEqual(128, \strlen($pending['verifier']));
        $this->assertEqualsWithDelta(time(), $pending['createdAt'], 5);
    }

    public function testInitiateIncludesShopName(): void
    {
        $this->configStore['core.basicInformation.shopName'] = 'My Demo Shop';
        $service = $this->createService();

        $url = $service->initiate('https://myshop.example.com');

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);
        $this->assertSame('My Demo Shop', $params['shop_name']);
    }

    public function testInitiateCanonicalizesHostCase(): void
    {
        $service = $this->createService();

        $url = $service->initiate('https://MyShop.Example.COM');

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);
        $this->assertSame('https://myshop.example.com', $params['shop_origin']);
    }

    public function testInitiateRejectsHttpOrigin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createService()->initiate('http://myshop.example.com');
    }

    public function testInitiateRejectsOriginWithPort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createService()->initiate('https://myshop.example.com:8443');
    }

    public function testInitiateRejectsOriginWithPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createService()->initiate('https://myshop.example.com/admin');
    }

    public function testInitiateRejectsHostWithoutDot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createService()->initiate('https://localhost');
    }

    public function testInitiateRejectsEmptyOrigin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createService()->initiate('');
    }

    // --- exchange() ---

    public function testExchangeHappyPathPersistsCredentials(): void
    {
        $this->createService()->initiate('https://myshop.example.com');
        $pending = $this->getPending();

        $history = [];
        $client = $this->createClientWithMock([
            new Response(200, [], json_encode([
                'store_id' => 'store-42',
                'webhook_secret' => 'super-secret',
                'webhook_url' => 'https://emporiqa.com/webhooks/sync/store-42/',
                'issued_at' => '2026-07-10T00:00:00Z',
            ])),
        ], $history);

        $result = $this->createService($client)->exchange('one-time-code', $pending['state']);

        $this->assertTrue($result['success']);
        $this->assertSame('store-42', $result['storeId']);
        $this->assertArrayNotHasKey('webhook_secret', $result);

        // Config persisted, with the store-id segment stripped from the webhook URL
        $this->assertSame('store-42', $this->configStore['EmporiqaIntegration.config.storeId']);
        $this->assertSame('super-secret', $this->configStore['EmporiqaIntegration.config.webhookSecret']);
        $this->assertSame('https://emporiqa.com/webhooks/sync/', $this->configStore['EmporiqaIntegration.config.webhookUrl']);

        // Pending nonce consumed
        $this->assertArrayNotHasKey(self::PENDING_KEY, $this->configStore);

        // Request shape: POST /connect/exchange with code + verifier + origin
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://emporiqa.com/connect/exchange', (string) $request->getUri());
        $sentBody = json_decode((string) $request->getBody(), true);
        $this->assertSame('one-time-code', $sentBody['code']);
        $this->assertSame($pending['verifier'], $sentBody['code_verifier']);
        $this->assertSame('https://myshop.example.com', $sentBody['shop_origin']);
    }

    /**
     * The exchange sends the PKCE verifier and receives the webhook secret: a
     * Webhook URL edited to http:// must never make it travel in cleartext.
     */
    public function testAnHttpWebhookUrlNeverSendsTheExchangeInCleartext(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->config->method('getWebhookUrl')->willReturn('http://emporiqa.com/webhooks/sync/');
        $this->assertStringStartsWith('https://emporiqa.com/connect/start?', $this->createService()->initiate('https://myshop.example.com'));
        $pending = $this->getPending();

        $history = [];
        $client = $this->createClientWithMock([
            new Response(200, [], (string) json_encode(['store_id' => 'store-42', 'webhook_secret' => 'super-secret'])),
        ], $history);
        $this->createService($client)->exchange('one-time-code', $pending['state']);

        $this->assertSame('https://emporiqa.com/connect/exchange', (string) $history[0]['request']->getUri());
    }

    public function testExchangeSendsTheActionsBaseUrlAndKeepsTheRulesStatus(): void
    {
        $this->createService()->initiate('https://myshop.example.com');
        $pending = $this->getPending();

        $history = [];
        $client = $this->createClientWithMock([
            new Response(200, [], json_encode([
                'store_id' => 'store-42',
                'webhook_secret' => 'super-secret',
                'rules_available' => true,
                'live_rules' => [],
            ])),
        ], $history);
        $this->config->expects($this->once())->method('saveRulesStatus')
            ->with($this->callback(fn (array $answer) => $answer['rules_available'] === true));

        $this->createService($client)->exchange('one-time-code', $pending['state']);

        $sentBody = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('https://myshop.example.com/emporiqa/', $sentBody['actions_base_url']);
    }

    public function testTheVerifierAnswersTheOriginProofOnlyWhileTheExchangeIsInFlight(): void
    {
        $this->createService()->initiate('https://myshop.example.com');
        $pending = $this->getPending();
        $service = null;
        $duringExchange = 'unset';

        $client = $this->createClientWithMock([
            function () use (&$service, &$duringExchange, $pending) {
                $duringExchange = $service->exchangingVerifier($pending['state']);

                return new Response(200, [], json_encode(['store_id' => 'store-42', 'webhook_secret' => 'super-secret']));
            },
        ]);
        $service = $this->createService($client);

        // Pending but not exchanging yet: no answer.
        $this->assertNull($service->exchangingVerifier($pending['state']));

        $service->exchange('one-time-code', $pending['state']);

        $this->assertSame($pending['verifier'], $duringExchange);
        $this->assertNull($service->exchangingVerifier($pending['state']));
    }

    public function testTheVerifierIsNotGivenForAnotherState(): void
    {
        $this->configStore[self::PENDING_KEY] = json_encode([
            'state' => 'real-state',
            'verifier' => str_repeat('v', 64),
            'origin' => 'https://myshop.example.com',
            'createdAt' => time(),
            'exchangingAt' => time(),
        ]);

        $this->assertNull($this->createService()->exchangingVerifier('other-state'));
        $this->assertSame(str_repeat('v', 64), $this->createService()->exchangingVerifier('real-state'));
    }

    public function testAClaimedHandshakeCannotBeExchangedTwice(): void
    {
        $this->configStore[self::PENDING_KEY] = json_encode([
            'state' => 'real-state',
            'verifier' => str_repeat('v', 64),
            'origin' => 'https://myshop.example.com',
            'createdAt' => time(),
            'exchangingAt' => time(),
        ]);
        $history = [];
        $client = $this->createClientWithMock([], $history);

        $result = $this->createService($client)->exchange('code', 'real-state');

        $this->assertFalse($result['success']);
        $this->assertCount(0, $history);
    }

    /**
     * Two callbacks racing with the same state: the claim is a compare-and-set
     * on the stored row, so only the one whose update lands may exchange.
     */
    public function testTheClaimIsAtomicAgainstAConcurrentCallback(): void
    {
        $raw = (string) json_encode([
            'state' => 'real-state',
            'verifier' => str_repeat('v', 64),
            'origin' => 'https://myshop.example.com',
            'createdAt' => time(),
        ]);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(json_encode(['_value' => $raw]));
        $connection->expects($this->once())->method('executeStatement')
            ->with($this->stringContains('UPDATE `system_config`'), $this->callback(fn (array $p) => $p['expected'] === $raw))
            ->willReturn(0);
        $history = [];
        $service = new ConnectService($this->systemConfig, $this->config, $this->logger, $this->createClientWithMock([], $history), $connection);

        $result = $service->exchange('code', 'real-state');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already used', $result['error']);
        $this->assertCount(0, $history);
    }

    public function testActionsBaseUrlUsesTheShortestHttpsDomainOnTheAdminHost(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([
            'https://myshop.example.com/de',
            'https://myshop.example.com/en/b2b',
            'http://myshop.example.com',
            'https://other.example.com',
        ]);
        $service = new ConnectService($this->systemConfig, $this->config, $this->logger, null, $connection);

        $this->assertSame('https://myshop.example.com/de/emporiqa/', $service->actionsBaseUrl('https://myshop.example.com'));
        $this->assertSame('https://admin.example.com/emporiqa/', $service->actionsBaseUrl('https://admin.example.com'));
    }

    public function testExchangeFailsWhenNoPendingHandshake(): void
    {
        $result = $this->createService()->exchange('code', 'state');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No pending connect handshake', $result['error']);
    }

    public function testExchangeFailsOnStateMismatch(): void
    {
        $this->createService()->initiate('https://myshop.example.com');

        $history = [];
        $client = $this->createClientWithMock([], $history);

        $result = $this->createService($client)->exchange('code', 'wrong-state');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('State mismatch', $result['error']);
        // No network call must be made on state mismatch
        $this->assertCount(0, $history);
    }

    public function testExchangeFailsWhenPendingExpired(): void
    {
        $this->configStore[self::PENDING_KEY] = json_encode([
            'state' => 'old-state',
            'verifier' => str_repeat('a', 64),
            'origin' => 'https://myshop.example.com',
            'createdAt' => time() - 901,
        ]);

        $result = $this->createService()->exchange('code', 'old-state');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('expired', $result['error']);
        // Stale nonce is cleaned up
        $this->assertArrayNotHasKey(self::PENDING_KEY, $this->configStore);
    }

    public function testExchangeConsumesPendingEvenWhenHttpFails(): void
    {
        $this->createService()->initiate('https://myshop.example.com');
        $pending = $this->getPending();

        $client = $this->createClientWithMock([
            new ConnectException('Connection refused', new Request('POST', 'https://emporiqa.com/connect/exchange')),
        ]);

        $result = $this->createService($client)->exchange('code', $pending['state']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Could not reach Emporiqa', $result['error']);
        // Single-use: nonce is gone even though the exchange failed
        $this->assertArrayNotHasKey(self::PENDING_KEY, $this->configStore);
        $this->assertArrayNotHasKey('EmporiqaIntegration.config.storeId', $this->configStore);
    }

    public function testExchangePropagatesBackendError(): void
    {
        $this->createService()->initiate('https://myshop.example.com');
        $pending = $this->getPending();

        $client = $this->createClientWithMock([
            new Response(400, [], json_encode(['error' => 'invalid_or_expired_code'])),
        ]);

        $result = $this->createService($client)->exchange('code', $pending['state']);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_or_expired_code', $result['error']);
        $this->assertArrayNotHasKey('EmporiqaIntegration.config.storeId', $this->configStore);
    }

    public function testExchangeFailsOnHttpErrorWithoutErrorField(): void
    {
        $this->createService()->initiate('https://myshop.example.com');
        $pending = $this->getPending();

        $client = $this->createClientWithMock([
            new Response(500, [], 'Internal Server Error'),
        ]);

        $result = $this->createService($client)->exchange('code', $pending['state']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('HTTP 500', $result['error']);
    }

    public function testExchangeRejectsIncompleteResponse(): void
    {
        $this->createService()->initiate('https://myshop.example.com');
        $pending = $this->getPending();

        $client = $this->createClientWithMock([
            new Response(200, [], json_encode(['store_id' => 'store-42'])),
        ]);

        $result = $this->createService($client)->exchange('code', $pending['state']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unexpected response', $result['error']);
        $this->assertArrayNotHasKey('EmporiqaIntegration.config.storeId', $this->configStore);
    }

    public function testExchangeRejectsWebhookUrlFromUnexpectedHost(): void
    {
        $this->createService()->initiate('https://myshop.example.com');
        $pending = $this->getPending();

        $client = $this->createClientWithMock([
            new Response(200, [], json_encode([
                'store_id' => 'store-42',
                'webhook_secret' => 'super-secret',
                'webhook_url' => 'https://evil.example.com/webhooks/sync/store-42/',
            ])),
        ]);

        $result = $this->createService($client)->exchange('code', $pending['state']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unexpected host', $result['error']);
        $this->assertArrayNotHasKey('EmporiqaIntegration.config.storeId', $this->configStore);
        $this->assertArrayNotHasKey('EmporiqaIntegration.config.webhookSecret', $this->configStore);
    }

    public function testExchangeFailsForMissingCodeOrState(): void
    {
        $service = $this->createService();

        $result = $service->exchange('', '');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Missing code or state', $result['error']);
    }
}
