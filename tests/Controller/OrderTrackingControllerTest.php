<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Controller;

use Emporiqa\ShopwarePlugin\Controller\OrderTrackingController;
use Emporiqa\ShopwarePlugin\Event\OrderTrackingResponseEvent;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\WebhookClient;
use PHPUnit\Framework\MockObject\MockObject;
use Emporiqa\ShopwarePlugin\Tests\Support\EntityCollectionHelper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrderTrackingControllerTest extends TestCase
{
    use EntityCollectionHelper;

    private ConfigServiceInterface&MockObject $config;
    private EntityRepository&MockObject $orderRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private OrderTrackingController $controller;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->controller = new OrderTrackingController(
            $this->config,
            $this->orderRepository,
            $this->eventDispatcher,
        );
    }

    public function testTrackingReturns404WhenOrderTrackingDisabled(): void
    {
        $context = $this->createSalesChannelContext('channel-1');
        $this->config->method('isOrderTrackingEnabled')->with('channel-1')->willReturn(false);

        $request = new Request();

        $response = $this->controller->tracking($request, $context);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Order tracking is disabled', $data['error']);
    }

    public function testTrackingReturns401ForMissingSignature(): void
    {
        $context = $this->createSalesChannelContext('channel-2');
        $this->config->method('isOrderTrackingEnabled')->with('channel-2')->willReturn(true);
        $this->config->method('getWebhookSecret')->with('channel-2')->willReturn('secret-abc');

        $request = new Request([], [], [], [], [], [], json_encode([
            'order_identifier' => '10001',
            'timestamp' => time(),
        ]));

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Unauthorized', $data['error']);
    }

    public function testTrackingReturns401WhenSecretEmpty(): void
    {
        $context = $this->createSalesChannelContext('channel-3');
        $this->config->method('isOrderTrackingEnabled')->with('channel-3')->willReturn(true);
        $this->config->method('getWebhookSecret')->with('channel-3')->willReturn('');

        $request = new Request([], [], [], [], [], [], json_encode([
            'order_identifier' => '10001',
            'timestamp' => time(),
        ]));
        $request->headers->set('X-Emporiqa-Signature', 'some-signature');

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Unauthorized', $data['error']);
    }

    public function testTrackingReturns401ForInvalidSignature(): void
    {
        $context = $this->createSalesChannelContext('channel-4');
        $this->config->method('isOrderTrackingEnabled')->with('channel-4')->willReturn(true);
        $this->config->method('getWebhookSecret')->with('channel-4')->willReturn('correct-secret');

        $body = json_encode([
            'order_identifier' => '10001',
            'timestamp' => time(),
        ]);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', 'wrong-signature-value');

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid signature', $data['error']);
    }

    public function testTrackingReturns401ForExpiredTimestamp(): void
    {
        $secret = 'tracking-secret';
        $context = $this->createSalesChannelContext('channel-5');
        $this->config->method('isOrderTrackingEnabled')->with('channel-5')->willReturn(true);
        $this->config->method('getWebhookSecret')->with('channel-5')->willReturn($secret);

        $body = json_encode([
            'order_identifier' => '10001',
            'timestamp' => time() - 600,
        ]);

        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Request expired', $data['error']);
    }

    public function testTrackingReturnsBadRequestForInvalidJson(): void
    {
        $secret = 'json-secret';
        $context = $this->createSalesChannelContext('channel-6');
        $this->config->method('isOrderTrackingEnabled')->with('channel-6')->willReturn(true);
        $this->config->method('getWebhookSecret')->with('channel-6')->willReturn($secret);

        $body = 'not-valid-json';
        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid JSON', $data['error']);
    }

    public function testTrackingReturnsBadRequestForMissingOrderIdentifier(): void
    {
        $secret = 'identifier-secret';
        $context = $this->createSalesChannelContext('channel-7');
        $this->config->method('isOrderTrackingEnabled')->with('channel-7')->willReturn(true);
        $this->config->method('getWebhookSecret')->with('channel-7')->willReturn($secret);

        $body = json_encode([
            'timestamp' => time(),
        ]);

        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Missing order identifier', $data['error']);
    }

    public function testTrackingMasksMissingEmailAsOrderNotFound(): void
    {
        // Anti-enumeration (H2): a failed email verification must be
        // indistinguishable from the order simply not existing.
        $secret = 'email-secret';
        $context = $this->createSalesChannelContext('channel-8');
        $this->config->method('isOrderTrackingEnabled')->with('channel-8')->willReturn(true);
        $this->config->method('getWebhookSecret')->with('channel-8')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->with('channel-8')->willReturn(true);

        $body = json_encode([
            'order_identifier' => '10001',
            'timestamp' => time(),
        ]);

        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $order = $this->createMock(OrderEntity::class);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Order not found.', $data['error']);
    }

    public function testTrackingReturnsOrderNotFoundWithTrailingPeriod(): void
    {
        $context = $this->createSalesChannelContext('channel-missing');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn('missing-secret');

        $body = json_encode(['order_identifier' => '99999', 'timestamp' => time()]);
        $signature = WebhookClient::generateSignature($body, 'missing-secret');

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn(null);
        $searchResult->method('getEntities')->willReturn(self::entityCollection(null));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Order not found.', $data['error']);
    }

    public function testTrackingMatchesEmailCaseInsensitivelyWhenRequired(): void
    {
        $secret = 'ci-email-secret';
        $context = $this->createSalesChannelContext('channel-ci-1');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(true);

        $body = json_encode([
            'order_identifier' => '10001',
            'timestamp' => time(),
            'verification_fields' => ['email' => 'John@Example.COM'],
        ]);

        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $orderCustomer = $this->createMock(OrderCustomerEntity::class);
        $orderCustomer->method('getEmail')->willReturn('john@example.com');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderCustomer')->willReturn($orderCustomer);
        $order->method('getOrderNumber')->willReturn('10001');
        $order->method('getOrderDateTime')->willReturn(new \DateTimeImmutable());
        $order->method('getAmountTotal')->willReturn(99.99);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testTrackingMatchesEmailCaseInsensitivelyWhenOptional(): void
    {
        $secret = 'ci-email-opt-secret';
        $context = $this->createSalesChannelContext('channel-ci-2');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(false);

        $body = json_encode([
            'order_identifier' => '10002',
            'timestamp' => time(),
            'verification_fields' => ['email' => 'JANE@SHOP.DE'],
        ]);

        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $orderCustomer = $this->createMock(OrderCustomerEntity::class);
        $orderCustomer->method('getEmail')->willReturn('jane@shop.de');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderCustomer')->willReturn($orderCustomer);
        $order->method('getOrderNumber')->willReturn('10002');
        $order->method('getOrderDateTime')->willReturn(new \DateTimeImmutable());
        $order->method('getAmountTotal')->willReturn(49.99);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testTrackingRejectsWrongEmailCaseInsensitively(): void
    {
        $secret = 'ci-email-wrong';
        $context = $this->createSalesChannelContext('channel-ci-3');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(true);

        $body = json_encode([
            'order_identifier' => '10003',
            'timestamp' => time(),
            'verification_fields' => ['email' => 'wrong@email.com'],
        ]);

        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $orderCustomer = $this->createMock(OrderCustomerEntity::class);
        $orderCustomer->method('getEmail')->willReturn('correct@email.com');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderCustomer')->willReturn($orderCustomer);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Order not found.', $data['error']);
    }

    public function testTrackingTrimsEmailWhitespace(): void
    {
        $secret = 'trim-secret';
        $context = $this->createSalesChannelContext('channel-trim');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(true);

        $body = json_encode([
            'order_identifier' => '10004',
            'timestamp' => time(),
            'verification_fields' => ['email' => '  user@example.com  '],
        ]);

        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $orderCustomer = $this->createMock(OrderCustomerEntity::class);
        $orderCustomer->method('getEmail')->willReturn('user@example.com');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderCustomer')->willReturn($orderCustomer);
        $order->method('getOrderNumber')->willReturn('10004');
        $order->method('getOrderDateTime')->willReturn(new \DateTimeImmutable());
        $order->method('getAmountTotal')->willReturn(10.00);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // --- Carrier / tracking fields (H1) ---

    public function testTrackingIncludesCarrierAndTrackingFields(): void
    {
        $secret = 'track-secret';
        $context = $this->createSalesChannelContext('channel-track');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(false);

        $body = json_encode(['order_identifier' => '10006', 'timestamp' => time()]);
        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $shippingMethod = $this->createMock(ShippingMethodEntity::class);
        $shippingMethod->method('getTranslation')->willReturnCallback(fn(string $f) => $f === 'name' ? 'DHL Express' : null);
        $shippingMethod->method('getName')->willReturn('DHL Express');
        $shippingMethod->method('getTrackingUrl')->willReturn('https://track.dhl.com/%s');

        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getShippingMethod')->willReturn($shippingMethod);
        $delivery->method('getTrackingCodes')->willReturn(['ABC123456']);

        $deliveries = new OrderDeliveryCollection([$delivery]);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('10006');
        $order->method('getOrderDateTime')->willReturn(new \DateTimeImmutable());
        $order->method('getAmountTotal')->willReturn(10.0);
        $order->method('getDeliveries')->willReturn($deliveries);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);
        $data = json_decode($response->getContent(), true);

        $this->assertSame('DHL Express', $data['carrier']);
        $this->assertSame('ABC123456', $data['tracking_number']);
        $this->assertSame('https://track.dhl.com/ABC123456', $data['tracking_url']);
    }

    public function testTrackingFieldsAreNullWhenNoDeliveryExists(): void
    {
        $secret = 'no-delivery-secret';
        $context = $this->createSalesChannelContext('channel-no-delivery');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(false);

        $body = json_encode(['order_identifier' => '10007', 'timestamp' => time()]);
        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('10007');
        $order->method('getOrderDateTime')->willReturn(new \DateTimeImmutable());
        $order->method('getAmountTotal')->willReturn(10.0);
        $order->method('getDeliveries')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);
        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('carrier', $data);
        $this->assertArrayHasKey('tracking_number', $data);
        $this->assertArrayHasKey('tracking_url', $data);
        $this->assertNull($data['carrier']);
        $this->assertNull($data['tracking_number']);
        $this->assertNull($data['tracking_url']);
    }

    public function testTrackingUrlIsNullWhenNoTrackingNumber(): void
    {
        $secret = 'no-tracking-secret';
        $context = $this->createSalesChannelContext('channel-no-tracking');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(false);

        $body = json_encode(['order_identifier' => '10008', 'timestamp' => time()]);
        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $shippingMethod = $this->createMock(ShippingMethodEntity::class);
        $shippingMethod->method('getName')->willReturn('Standard Shipping');
        $shippingMethod->method('getTrackingUrl')->willReturn('https://track.example.com/%s');

        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getShippingMethod')->willReturn($shippingMethod);
        $delivery->method('getTrackingCodes')->willReturn([]);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('10008');
        $order->method('getOrderDateTime')->willReturn(new \DateTimeImmutable());
        $order->method('getAmountTotal')->willReturn(10.0);
        $order->method('getDeliveries')->willReturn(new OrderDeliveryCollection([$delivery]));

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);
        $data = json_decode($response->getContent(), true);

        $this->assertSame('Standard Shipping', $data['carrier']);
        $this->assertNull($data['tracking_number']);
        $this->assertNull($data['tracking_url']);
    }

    public function testTrackingUrlWithStrayPercentSpecifiersDoesNotThrow(): void
    {
        // A template containing literal '%20' (e.g. a percent-encoded URL)
        // must not be treated as a sprintf format specifier — str_replace
        // has no format-string semantics and never throws a ValueError.
        $secret = 'stray-percent-secret';
        $context = $this->createSalesChannelContext('channel-stray-percent');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(false);

        $body = json_encode(['order_identifier' => '10011', 'timestamp' => time()]);
        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $shippingMethod = $this->createMock(ShippingMethodEntity::class);
        $shippingMethod->method('getName')->willReturn('Percent Shipping');
        $shippingMethod->method('getTrackingUrl')->willReturn('https://track.example.com/%20path/%s');

        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getShippingMethod')->willReturn($shippingMethod);
        $delivery->method('getTrackingCodes')->willReturn(['ABC123456']);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('10011');
        $order->method('getOrderDateTime')->willReturn(new \DateTimeImmutable());
        $order->method('getAmountTotal')->willReturn(10.0);
        $order->method('getDeliveries')->willReturn(new OrderDeliveryCollection([$delivery]));

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('https://track.example.com/%20path/ABC123456', $data['tracking_url']);
    }

    public function testTrackingUrlIsNullWhenTemplateHasNoPlaceholder(): void
    {
        $secret = 'no-placeholder-secret';
        $context = $this->createSalesChannelContext('channel-no-placeholder');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(false);

        $body = json_encode(['order_identifier' => '10012', 'timestamp' => time()]);
        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $shippingMethod = $this->createMock(ShippingMethodEntity::class);
        $shippingMethod->method('getName')->willReturn('Static Link Shipping');
        $shippingMethod->method('getTrackingUrl')->willReturn('https://track.example.com/lookup');

        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getShippingMethod')->willReturn($shippingMethod);
        $delivery->method('getTrackingCodes')->willReturn(['ABC123456']);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('10012');
        $order->method('getOrderDateTime')->willReturn(new \DateTimeImmutable());
        $order->method('getAmountTotal')->willReturn(10.0);
        $order->method('getDeliveries')->willReturn(new OrderDeliveryCollection([$delivery]));

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $response = $this->controller->tracking($request, $context);
        $data = json_decode($response->getContent(), true);

        $this->assertSame('ABC123456', $data['tracking_number']);
        $this->assertNull($data['tracking_url']);
    }

    // --- Language localization (H3) ---

    public function testTrackingRefetchesOrderInItsOwnLanguage(): void
    {
        $secret = 'lang-secret';
        $context = $this->createSalesChannelContext('channel-lang');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(false);

        $body = json_encode(['order_identifier' => '10009', 'timestamp' => time()]);
        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        // Order language differs from the request context's default language
        // (Defaults::LANGUAGE_SYSTEM), so a second, language-scoped search
        // must be issued and its result used for the response.
        $orderLanguageId = 'order-specific-language-id';

        $initialOrder = $this->createMock(OrderEntity::class);
        $initialOrder->method('getOrderNumber')->willReturn('10009');
        $initialOrder->method('getLanguageId')->willReturn($orderLanguageId);

        $localizedOrder = $this->createMock(OrderEntity::class);
        $localizedOrder->method('getOrderNumber')->willReturn('10009-DE');
        $localizedOrder->method('getLanguageId')->willReturn($orderLanguageId);
        $localizedOrder->method('getOrderDateTime')->willReturn(new \DateTimeImmutable());
        $localizedOrder->method('getAmountTotal')->willReturn(20.0);

        $callCount = 0;
        $this->orderRepository->method('search')->willReturnCallback(
            function () use (&$callCount, $initialOrder, $localizedOrder) {
                $callCount++;
                $result = $this->createMock(EntitySearchResult::class);
                $result->method('first')->willReturn($callCount === 1 ? $initialOrder : $localizedOrder);
                $result->method('getEntities')->willReturn(self::entityCollection($callCount === 1 ? $initialOrder : $localizedOrder));

                return $result;
            },
        );

        $response = $this->controller->tracking($request, $context);
        $data = json_decode($response->getContent(), true);

        $this->assertSame(2, $callCount);
        $this->assertSame('10009-DE', $data['order_number']);
    }

    // --- Extension point (H4) ---

    public function testTrackingDispatchesResponseEventAndUsesMutatedData(): void
    {
        $secret = 'event-secret';
        $context = $this->createSalesChannelContext('channel-event');
        $this->config->method('isOrderTrackingEnabled')->willReturn(true);
        $this->config->method('getWebhookSecret')->willReturn($secret);
        $this->config->method('isOrderRequireEmail')->willReturn(false);

        $body = json_encode(['order_identifier' => '10010', 'timestamp' => time()]);
        $signature = WebhookClient::generateSignature($body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('10010');
        $order->method('getOrderDateTime')->willReturn(new \DateTimeImmutable());
        $order->method('getAmountTotal')->willReturn(5.0);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($order));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                if (!$event instanceof OrderTrackingResponseEvent) {
                    return false;
                }
                $event->setData(array_merge($event->getData(), ['injected_field' => 'injected-value']));

                return true;
            }));

        $response = $this->controller->tracking($request, $context);
        $data = json_decode($response->getContent(), true);

        $this->assertSame('injected-value', $data['injected_field']);
    }

    /**
     * @return SalesChannelContext&MockObject
     */
    private function createSalesChannelContext(string $salesChannelId): SalesChannelContext&MockObject
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
