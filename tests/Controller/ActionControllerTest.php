<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Controller;

use Emporiqa\ShopwarePlugin\Controller\ActionController;
use Emporiqa\ShopwarePlugin\Service\ActionGuard;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\ConnectServiceInterface;
use Emporiqa\ShopwarePlugin\Service\CustomerInfoService;
use Emporiqa\ShopwarePlugin\Service\CustomerPriceService;
use Emporiqa\ShopwarePlugin\Service\OrderStatusService;
use Emporiqa\ShopwarePlugin\Service\SignatureHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ActionControllerTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef0123456789abcdef';
    private const OLD_SECRET = 'old-secret-fedcba9876543210fedcba9876543210';
    private const STORE_ID = 'st_7Kq2mXa9';
    private const CUSTOMER = '0190aaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PRODUCT = '0190cccccccccccccccccccccccccccc';

    private ConfigServiceInterface&MockObject $config;
    private ConnectServiceInterface&MockObject $connect;
    private OrderStatusService&MockObject $orderStatus;
    private ActionGuard&MockObject $guard;
    private CustomerPriceService&MockObject $customerPrices;
    private CustomerInfoService&MockObject $customerInfo;
    private ActionController $controller;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->config->method('getWebhookSecret')->willReturn(self::SECRET);
        $this->config->method('getStoreId')->willReturn(self::STORE_ID);
        $this->connect = $this->createMock(ConnectServiceInterface::class);
        $this->orderStatus = $this->createMock(OrderStatusService::class);
        $this->guard = $this->createMock(ActionGuard::class);
        $this->customerPrices = $this->createMock(CustomerPriceService::class);
        $this->customerInfo = $this->createMock(CustomerInfoService::class);

        $this->controller = new ActionController(
            $this->config,
            $this->connect,
            $this->orderStatus,
            $this->customerPrices,
            $this->guard,
            $this->createMock(LoggerInterface::class),
            $this->customerInfo,
        );
    }

    // --- signature ---

    public function testUnsignedCallIsRefusedUnsigned(): void
    {
        $response = $this->controller->orderStatus($this->request($this->orderBody(), null), $this->context());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['status' => 'error', 'message_code' => 'signature'], $this->decode($response));
        $this->assertFalse($response->headers->has('X-Emporiqa-Response-Signature'));
    }

    public function testWrongSecretIsRefused(): void
    {
        $body = $this->orderBody();
        $response = $this->controller->orderStatus($this->request($body, $this->sign($body, 'another-secret')), $this->context());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('signature', $this->decode($response)['message_code']);
    }

    public function testResponseLabelIsNotAcceptedAsARequest(): void
    {
        $body = $this->orderBody();
        $key = SignatureHelper::deriveKey(self::SECRET, SignatureHelper::LABEL_RESPONSE, self::STORE_ID);
        $response = $this->controller->orderStatus($this->request($body, SignatureHelper::buildHeader($key, $body)), $this->context());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testExpiredTimestampIsRefused(): void
    {
        $body = $this->orderBody();
        $response = $this->controller->orderStatus($this->request($body, $this->sign($body, self::SECRET, time() - 301)), $this->context());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('expired', $this->decode($response)['message_code']);
    }

    public function testRotationHeaderIsAcceptedHoldingEitherSecret(): void
    {
        $body = $this->orderBody();
        $t = time();
        $header = $this->sign($body, self::OLD_SECRET, $t) . ',v1=' . explode('v1=', $this->sign($body, self::SECRET, $t))[1];
        $this->guard->method('rateLimitHit')->willReturn(null);
        $this->orderStatus->method('handle')->willReturn(['status' => 'not_found']);

        $response = $this->controller->orderStatus($this->request($body, $header), $this->context());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNotConnectedAnswersDisabled(): void
    {
        $config = $this->createMock(ConfigServiceInterface::class);
        $config->method('getWebhookSecret')->willReturn('');
        $controller = new ActionController($config, $this->connect, $this->orderStatus, $this->customerPrices, $this->guard, $this->createMock(LoggerInterface::class), $this->customerInfo);

        $response = $controller->orderStatus($this->request($this->orderBody(), null), $this->context());

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('disabled', $this->decode($response)['message_code']);
    }

    // --- order_status ---

    public function testFoundAnswerIsSignedOverRequestIdAndBody(): void
    {
        $body = $this->orderBody();
        $this->guard->method('rateLimitHit')->willReturn(null);
        $this->orderStatus->method('handle')->willReturn(['status' => 'found', 'data' => ['status_code' => 'shipped']]);
        $this->guard->expects($this->once())->method('remember')->with(self::STORE_ID, 'req-1', 200, $this->anything());

        $response = $this->controller->orderStatus($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('found', $this->decode($response)['status']);
        $this->assertResponseSigned($response, 'req-1');
    }

    public function testReplayGetsTheSameAnswerWithoutALookup(): void
    {
        $body = $this->orderBody();
        $this->guard->method('remembered')->with(self::STORE_ID, 'req-1')->willReturn([200, '{"status":"not_found"}']);
        $this->guard->expects($this->never())->method('rateLimitHit');
        $this->orderStatus->expects($this->never())->method('handle');

        $response = $this->controller->orderStatus($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame('{"status":"not_found"}', $response->getContent());
        $this->assertResponseSigned($response, 'req-1');
    }

    public function testRateLimitAnswersSigned429WithScopeAndRetryAfter(): void
    {
        $body = $this->orderBody();
        $this->guard->method('rateLimitHit')->with(self::STORE_ID, '1042', 'a@b.c')->willReturn(['scope' => 'value', 'retry_after' => 120]);
        $this->orderStatus->expects($this->never())->method('handle');

        $response = $this->controller->orderStatus($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(['status' => 'error', 'message_code' => 'rate_limited', 'data' => ['scope' => 'value']], $this->decode($response));
        $this->assertSame('120', $response->headers->get('Retry-After'));
        $this->assertResponseSigned($response, 'req-1');
    }

    public function testMissingRequestIdIsInvalid(): void
    {
        $body = (string) json_encode(['rule' => 'order_status', 'fields' => []]);

        $response = $this->controller->orderStatus($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_field', $this->decode($response)['message_code']);
    }

    public function testAnErrorAnswersInternalWithoutDetails(): void
    {
        $body = $this->orderBody();
        $this->guard->method('rateLimitHit')->willReturn(null);
        $this->orderStatus->method('handle')->willThrowException(new \RuntimeException('SQLSTATE secret detail'));

        $response = $this->controller->orderStatus($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['status' => 'error', 'message_code' => 'internal'], $this->decode($response));
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
    }

    public function testErrorLogNamesTheExceptionButNeverItsMessage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->logicalAnd(
            $this->stringContains('RuntimeException'),
            $this->logicalNot($this->stringContains('a@b.c')),
        ));
        $controller = new ActionController($this->config, $this->connect, $this->orderStatus, $this->customerPrices, $this->guard, $logger, $this->customerInfo);
        $this->guard->method('rateLimitHit')->willReturn(null);
        $this->orderStatus->method('handle')->willThrowException(new \RuntimeException("Duplicate entry 'a@b.c'"));

        $body = $this->orderBody();
        $controller->orderStatus($this->request($body, $this->sign($body)), $this->context());
    }

    // --- customer_prices ---

    public function testCustomerPricesAnswerIsSignedAndRemembered(): void
    {
        $body = $this->pricesBody();
        $this->guard->method('customerPriceLimitHit')->with(self::STORE_ID, self::CUSTOMER)->willReturn(null);
        $this->customerPrices->expects($this->once())->method('handle')
            ->with($this->anything(), self::CUSTOMER, [self::PRODUCT], $this->anything(), self::STORE_ID)
            ->willReturn(['status' => 'found', 'data' => ['currency' => 'EUR', 'prices_include_tax' => true, 'products' => []]]);
        $this->guard->expects($this->once())->method('remember')->with(self::STORE_ID, 'req-p', 200, $this->anything());

        $response = $this->controller->customerPrices($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('found', $this->decode($response)['status']);
        $this->assertResponseSigned($response, 'req-p');
    }

    public function testCustomerPricesUnsignedIsRefused(): void
    {
        $this->customerPrices->expects($this->never())->method('handle');

        $response = $this->controller->customerPrices($this->request($this->pricesBody(), null), $this->context());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCustomerPricesForAnotherRuleNameIsInvalid(): void
    {
        $body = $this->orderBody();
        $this->customerPrices->expects($this->never())->method('handle');

        $response = $this->controller->customerPrices($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCustomerPricesWithoutCustomerOrWithTooManyProductsIsInvalid(): void
    {
        $this->customerPrices->expects($this->never())->method('handle');
        $noCustomer = $this->pricesBody(['customer' => null]);
        $tooMany = $this->pricesBody(['products' => array_fill(0, CustomerPriceService::MAX_PRODUCTS + 1, 'product-' . self::PRODUCT)]);

        $this->assertSame(400, $this->controller->customerPrices($this->request($noCustomer, $this->sign($noCustomer)), $this->context())->getStatusCode());
        $this->assertSame(400, $this->controller->customerPrices($this->request($tooMany, $this->sign($tooMany)), $this->context())->getStatusCode());
    }

    public function testCustomerPricesRateLimitIsASigned429(): void
    {
        $body = $this->pricesBody();
        $this->guard->method('customerPriceLimitHit')->willReturn(['scope' => 'value', 'retry_after' => 60]);
        $this->customerPrices->expects($this->never())->method('handle');

        $response = $this->controller->customerPrices($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('value', $this->decode($response)['data']['scope']);
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertResponseSigned($response, 'req-p');
    }

    public function testCustomerPricesReplayGetsTheSameAnswerWithoutComputing(): void
    {
        $body = $this->pricesBody();
        $this->guard->method('remembered')->with(self::STORE_ID, 'req-p')->willReturn([200, '{"status":"not_found"}']);
        $this->guard->expects($this->never())->method('customerPriceLimitHit');
        $this->customerPrices->expects($this->never())->method('handle');

        $response = $this->controller->customerPrices($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame('{"status":"not_found"}', $response->getContent());
    }

    public function testCustomerPricesErrorAnswersInternalWithoutDetails(): void
    {
        $body = $this->pricesBody();
        $this->guard->method('customerPriceLimitHit')->willReturn(null);
        $this->customerPrices->method('handle')->willThrowException(new \RuntimeException('SQLSTATE detail'));

        $response = $this->controller->customerPrices($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['status' => 'error', 'message_code' => 'internal'], $this->decode($response));
    }

    // --- verify ---

    public function testChallengeIsAnsweredWithTheResponseKey(): void
    {
        $challenge = str_repeat('ab', 32);
        $body = (string) json_encode(['rule' => 'verify', 'challenge' => $challenge, 'request_id' => 'req-v', 'test' => true]);

        $response = $this->controller->verify($this->request($body, $this->sign($body)), $this->context());

        $key = SignatureHelper::deriveKey(self::SECRET, SignatureHelper::LABEL_RESPONSE, self::STORE_ID);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(hash_hmac('sha256', $challenge, $key), $this->decode($response)['data']['challenge_mac']);
        $this->assertResponseSigned($response, 'req-v');
    }

    public function testUnsignedChallengeIsRefused(): void
    {
        $body = (string) json_encode(['rule' => 'verify', 'challenge' => str_repeat('ab', 32), 'request_id' => 'req-v']);

        $response = $this->controller->verify($this->request($body, null), $this->context());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testMalformedChallengeIsRejected(): void
    {
        $body = (string) json_encode(['rule' => 'verify', 'challenge' => 'ABC', 'request_id' => 'req-v']);

        $response = $this->controller->verify($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame('rejected', $this->decode($response)['status']);
    }

    public function testOriginProofAnswersOnlyForTheExchangeInFlight(): void
    {
        $nonce = str_repeat('0f', 32);
        $this->connect->method('exchangingVerifier')->willReturnCallback(fn (string $state) => $state === 'state-1' ? 'verifier-1' : null);

        $ok = $this->controller->verify($this->request((string) json_encode(['state' => 'state-1', 'nonce' => $nonce]), null), $this->context());
        $other = $this->controller->verify($this->request((string) json_encode(['state' => 'state-2', 'nonce' => $nonce]), null), $this->context());

        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(hash_hmac('sha256', $nonce, 'verifier-1'), $this->decode($ok)['data']['nonce_mac']);
        $this->assertSame(404, $other->getStatusCode());
    }

    public function testOriginProofRefusesANonceOfTheCallersChoosing(): void
    {
        $this->connect->expects($this->never())->method('exchangingVerifier');

        $response = $this->controller->verify($this->request((string) json_encode(['state' => 'state-1', 'nonce' => 'pick-me']), null), $this->context());

        $this->assertSame(404, $response->getStatusCode());
    }

    // --- customer_info ---

    public function testCustomerInfoAnswerIsSignedAndRemembered(): void
    {
        $body = $this->infoBody();
        $this->guard->expects($this->once())->method('customerInfoLimitHit')->with(self::STORE_ID, self::CUSTOMER)->willReturn(null);
        $this->customerInfo->expects($this->once())->method('handle')
            ->with($this->callback(fn (array $p) => $p['customer']['id'] === self::CUSTOMER), $this->anything(), self::STORE_ID)
            ->willReturn(['status' => 'found', 'data' => ['customer' => ['first_name' => 'Ada'], 'orders' => []]]);
        $this->guard->expects($this->once())->method('remember')->with(self::STORE_ID, 'req-i', 200, $this->anything());

        $response = $this->controller->customerInfo($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['customer' => ['first_name' => 'Ada'], 'orders' => []], $this->decode($response)['data']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertResponseSigned($response, 'req-i');
    }

    /**
     * The owner's Try it sends test:true inside the signed body: answered
     * with real data under the normal limits (the call has no side effects).
     */
    public function testCustomerInfoTestCallIsAnsweredUnderTheNormalLimits(): void
    {
        $body = $this->infoBody(['test' => true]);
        $this->guard->expects($this->once())->method('customerInfoLimitHit')->willReturn(null);
        $this->customerInfo->expects($this->once())->method('handle')->willReturn(['status' => 'not_found']);

        $response = $this->controller->customerInfo($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'not_found'], $this->decode($response));
    }

    public function testCustomerInfoUnsignedIsRefusedWithoutALookup(): void
    {
        $this->customerInfo->expects($this->never())->method('handle');
        $this->guard->expects($this->never())->method('customerInfoLimitHit');

        $response = $this->controller->customerInfo($this->request($this->infoBody(), null), $this->context());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($response->headers->has('X-Emporiqa-Response-Signature'));
    }

    public function testCustomerInfoForAnotherRuleNameIsInvalid(): void
    {
        $body = $this->pricesBody();
        $this->customerInfo->expects($this->never())->method('handle');

        $this->assertSame(400, $this->controller->customerInfo($this->request($body, $this->sign($body)), $this->context())->getStatusCode());
    }

    public function testCustomerInfoWithoutACustomerIsNotCounted(): void
    {
        $body = $this->infoBody(['customer' => null]);
        $this->guard->expects($this->never())->method('customerInfoLimitHit');
        $this->customerInfo->method('handle')->willReturn(['status' => 'rejected', 'message_code' => 'missing_field']);

        $response = $this->controller->customerInfo($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('rejected', $this->decode($response)['status']);
    }

    public function testCustomerInfoRateLimitIsASigned429(): void
    {
        $body = $this->infoBody();
        $this->guard->method('customerInfoLimitHit')->willReturn(['scope' => 'store', 'retry_after' => 120]);
        $this->customerInfo->expects($this->never())->method('handle');

        $response = $this->controller->customerInfo($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('store', $this->decode($response)['data']['scope']);
        $this->assertSame('120', $response->headers->get('Retry-After'));
        $this->assertResponseSigned($response, 'req-i');
    }

    public function testCustomerInfoReplayGetsTheSameAnswerWithoutALookup(): void
    {
        $body = $this->infoBody();
        $this->guard->method('remembered')->with(self::STORE_ID, 'req-i')->willReturn([200, '{"status":"found","data":{"orders":[]}}']);
        $this->guard->expects($this->never())->method('customerInfoLimitHit');
        $this->customerInfo->expects($this->never())->method('handle');

        $response = $this->controller->customerInfo($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame('{"status":"found","data":{"orders":[]}}', $response->getContent());
        $this->assertResponseSigned($response, 'req-i');
    }

    public function testCustomerInfoErrorAnswersInternalWithoutDetails(): void
    {
        $body = $this->infoBody();
        $this->guard->method('customerInfoLimitHit')->willReturn(null);
        $this->customerInfo->method('handle')->willThrowException(new \RuntimeException("Duplicate entry 'ada@example.com'"));

        $response = $this->controller->customerInfo($this->request($body, $this->sign($body)), $this->context());

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['status' => 'error', 'message_code' => 'internal'], $this->decode($response));
    }

    public function testCustomerInfoAnswersInMaintenanceMode(): void
    {
        $this->assertContains('frontend.emporiqa.actions.customer_info', ActionController::ROUTES);
    }

    // --- helpers ---

    /**
     * @param array<string, mixed> $override
     */
    private function pricesBody(array $override = []): string
    {
        return (string) json_encode(array_merge([
            'rule' => 'customer_prices',
            'request_id' => 'req-p',
            'currency' => 'EUR',
            'country' => 'DE',
            'channel' => 'storefront',
            'language' => 'de',
            'customer' => ['id' => self::CUSTOMER],
            'products' => ['product-' . self::PRODUCT],
        ], $override));
    }

    /**
     * @param array<string, mixed> $override
     */
    private function infoBody(array $override = []): string
    {
        return (string) json_encode(array_merge([
            'rule' => 'customer_info',
            'request_id' => 'req-i',
            'customer' => ['id' => self::CUSTOMER],
            'language' => 'de',
        ], $override));
    }

    private function orderBody(): string
    {
        return (string) json_encode([
            'rule' => 'order_status',
            'request_id' => 'req-1',
            'fields' => ['order_number' => '1042', 'email' => 'a@b.c'],
            'customer' => null,
        ]);
    }

    private function sign(string $body, string $secret = self::SECRET, ?int $t = null): string
    {
        $key = SignatureHelper::deriveKey($secret, SignatureHelper::LABEL_OUTBOUND, self::STORE_ID);

        return SignatureHelper::buildHeader($key, $body, $t);
    }

    private function request(string $body, ?string $signature): Request
    {
        $server = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_EMPORIQA_ACTION_SIGNATURE'] = $signature;
        }

        return new Request([], [], [], [], [], $server, $body);
    }

    private function context(): SalesChannelContext&MockObject
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('channel-1');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    private function assertResponseSigned(Response $response, string $requestId): void
    {
        $header = (string) $response->headers->get('X-Emporiqa-Response-Signature');
        $this->assertSame(
            'ok',
            SignatureHelper::verifyHeader($header, $requestId . '.' . $response->getContent(), self::SECRET, self::STORE_ID, SignatureHelper::LABEL_RESPONSE),
        );
    }
}
