<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\ChannelResolverInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\OrderStatusService;
use Emporiqa\ShopwarePlugin\Tests\Support\EntityCollectionHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrderStatusServiceTest extends TestCase
{
    use EntityCollectionHelper;

    private const CUSTOMER_A = '0190aaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CUSTOMER_B = '0190bbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const STORE = 'st_1';

    private EntityRepository&MockObject $orderRepository;
    private ConfigServiceInterface&MockObject $config;
    private OrderStatusService $service;

    /** @var list<Criteria> */
    private array $searches = [];

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->config->method('getEnabledSalesChannels')->willReturn(['channel-1', 'channel-other-store']);
        $this->config->method('getStoreId')->willReturnCallback(fn (?string $id) => $id === 'channel-other-store' ? 'st_2' : self::STORE);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $this->service = new OrderStatusService(
            $this->orderRepository,
            $this->config,
            $this->createMock(ChannelResolverInterface::class),
            $dispatcher,
        );
    }

    public function testMissingFieldsAreRejectedBeforeAnyLookup(): void
    {
        $this->orderRepository->expects($this->never())->method('search');

        $result = $this->service->handle(['fields' => []], Context::createDefaultContext(), self::STORE);

        $this->assertSame(['status' => 'rejected', 'ask' => ['order_number', 'email'], 'message_code' => 'missing_field'], $result);
    }

    public function testASignedInCustomerNeedsNoEmail(): void
    {
        $this->returnOrders($this->order(self::CUSTOMER_A, 'a@shop.test'));

        $result = $this->service->handle(
            ['fields' => ['order_number' => '10001'], 'customer' => ['id' => self::CUSTOMER_A]],
            Context::createDefaultContext(),
            self::STORE,
        );

        $this->assertSame('found', $result['status']);
    }

    public function testMalformedValuesAreRejected(): void
    {
        $this->orderRepository->expects($this->never())->method('search');

        $this->assertSame('invalid_field', $this->service->handle(['fields' => ['order_number' => '1 OR 1', 'email' => 'a@b.c']], Context::createDefaultContext(), self::STORE)['message_code']);
        $this->assertSame('invalid_field', $this->service->handle(['fields' => ['order_number' => '1042', 'email' => 'nope']], Context::createDefaultContext(), self::STORE)['message_code']);
        $this->assertSame('invalid_field', $this->service->handle(['fields' => ['order_number' => str_repeat('1', 65), 'email' => 'a@b.c']], Context::createDefaultContext(), self::STORE)['message_code']);
    }

    public function testUnknownOrderAndWrongEmailGiveTheSameAnswer(): void
    {
        $this->returnOrders();
        $unknown = $this->service->handle(['fields' => ['order_number' => '10001', 'email' => 'a@shop.test']], Context::createDefaultContext(), self::STORE);

        $this->setUp();
        $this->returnOrders($this->order(self::CUSTOMER_A, 'a@shop.test'));
        $wrongEmail = $this->service->handle(['fields' => ['order_number' => '10001', 'email' => 'x@shop.test']], Context::createDefaultContext(), self::STORE);

        $this->assertSame(['status' => 'not_found'], $unknown);
        $this->assertSame($unknown, $wrongEmail);
    }

    public function testAnotherCustomersOrderIsNotFound(): void
    {
        $this->returnOrders($this->order(self::CUSTOMER_A, 'a@shop.test'));

        $result = $this->service->handle(
            ['fields' => ['order_number' => '10001'], 'customer' => ['id' => self::CUSTOMER_B]],
            Context::createDefaultContext(),
            self::STORE,
        );

        $this->assertSame(['status' => 'not_found'], $result);
    }

    public function testASignedInShopperFindsTheirGuestOrderByEmail(): void
    {
        $this->returnOrders($this->order(null, 'a@shop.test'));

        $result = $this->service->handle(
            ['fields' => ['order_number' => '10001', 'email' => 'A@Shop.Test'], 'customer' => ['id' => self::CUSTOMER_B]],
            Context::createDefaultContext(),
            self::STORE,
        );

        $this->assertSame('found', $result['status']);
    }

    /**
     * Only the synced sales channels of the store that signed the request are
     * searched: one shop can hold several Emporiqa stores.
     */
    public function testLookupIsScopedToTheSyncedSalesChannelsOfTheSigningStore(): void
    {
        $this->returnOrders();

        $this->service->handle(['fields' => ['order_number' => '10001', 'email' => 'a@shop.test']], Context::createDefaultContext(), self::STORE);

        $scoped = array_filter($this->searches[0]->getFilters(), fn ($f) => $f instanceof EqualsAnyFilter && $f->getField() === 'salesChannelId');
        $this->assertCount(1, $scoped);
        $this->assertSame(['channel-1'], array_values($scoped)[0]->getValue());
    }

    public function testFoundDataFollowsTheCatalogSchema(): void
    {
        $order = $this->order(self::CUSTOMER_A, 'a@shop.test', 'open', 'paid', 'shipped', ['00340434161094042557']);
        $this->returnOrders($order);

        $result = $this->service->handle(['fields' => ['order_number' => '10001', 'email' => 'a@shop.test']], Context::createDefaultContext(), self::STORE);

        $this->assertSame('found', $result['status']);
        $data = $result['data'];
        $this->assertSame('shipped', $data['status_code']);
        $this->assertSame('Shipped', $data['status_label']);
        $this->assertSame('2026-10-01T10:00:00+00:00', $data['placed_at']);
        $this->assertSame([[
            'carrier' => 'DHL',
            'number' => '00340434161094042557',
            'url' => 'https://www.dhl.de/track?piececode=00340434161094042557',
        ]], $data['tracking']);
        $this->assertSame('2026-10-08', $data['estimated_delivery']);
        // Nothing beyond the schema: no address, items, totals or email.
        $this->assertSame(['status_code', 'status_label', 'placed_at', 'tracking', 'estimated_delivery'], array_keys($data));
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, string}>
     */
    public static function statusCases(): iterable
    {
        yield 'new, unpaid' => ['open', 'open', 'open', 'pending'];
        yield 'paid' => ['open', 'paid', 'open', 'processing'];
        yield 'in progress' => ['in_progress', 'open', 'open', 'processing'];
        yield 'partially shipped' => ['in_progress', 'paid', 'shipped_partially', 'partially_shipped'];
        yield 'completed' => ['completed', 'paid', 'shipped', 'completed'];
        yield 'cancelled' => ['cancelled', 'paid', 'open', 'cancelled'];
        yield 'refunded' => ['completed', 'refunded', 'shipped', 'refunded'];
        yield 'payment failed' => ['open', 'failed', 'open', 'failed'];
    }

    #[DataProvider('statusCases')]
    public function testStatusCodes(string $orderState, ?string $txState, ?string $deliveryState, string $expected): void
    {
        $this->returnOrders($this->order(self::CUSTOMER_A, 'a@shop.test', $orderState, $txState, $deliveryState));

        $result = $this->service->handle(['fields' => ['order_number' => '10001', 'email' => 'a@shop.test']], Context::createDefaultContext(), self::STORE);

        $this->assertSame($expected, $result['data']['status_code']);
    }

    private function returnOrders(OrderEntity ...$orders): void
    {
        $this->searches = [];
        $this->orderRepository->method('search')->willReturnCallback(function (Criteria $criteria) use ($orders) {
            $this->searches[] = $criteria;
            $result = $this->createMock(EntitySearchResult::class);
            $result->method('getEntities')->willReturn(self::entityCollection(...$orders));

            return $result;
        });
    }

    /**
     * @param list<string> $trackingCodes
     */
    private function order(
        ?string $customerId,
        string $email,
        string $orderState = 'open',
        ?string $txState = 'open',
        ?string $deliveryState = 'open',
        array $trackingCodes = [],
    ): OrderEntity {
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setOrderNumber('10001');
        $order->setLanguageId(Context::createDefaultContext()->getLanguageId());
        $order->setOrderDateTime(new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        $order->setStateMachineState($this->state($orderState));

        $orderCustomer = new OrderCustomerEntity();
        $orderCustomer->setId(Uuid::randomHex());
        $orderCustomer->setEmail($email);
        if ($customerId !== null) {
            $orderCustomer->setCustomerId($customerId);
        }
        $order->setOrderCustomer($orderCustomer);

        if ($txState !== null) {
            $transaction = new OrderTransactionEntity();
            $transaction->setId(Uuid::randomHex());
            $transaction->setCreatedAt(new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
            $transaction->setStateMachineState($this->state($txState));
            $order->setTransactions(new OrderTransactionCollection([$transaction]));
        }

        if ($deliveryState !== null) {
            $method = new ShippingMethodEntity();
            $method->setId(Uuid::randomHex());
            $method->setName('DHL');
            $method->setTranslated(['name' => 'DHL', 'trackingUrl' => 'https://www.dhl.de/track?piececode=%s']);
            $delivery = new OrderDeliveryEntity();
            $delivery->setId(Uuid::randomHex());
            $delivery->setStateMachineState($this->state($deliveryState));
            $delivery->setShippingMethod($method);
            $delivery->setTrackingCodes($trackingCodes);
            $delivery->setShippingDateLatest(new \DateTimeImmutable('2026-10-08T00:00:00+00:00'));
            $order->setDeliveries(new OrderDeliveryCollection([$delivery]));
        }

        return $order;
    }

    private function state(string $technicalName): StateMachineStateEntity
    {
        $state = new StateMachineStateEntity();
        $state->setId(Uuid::randomHex());
        $state->setTechnicalName($technicalName);
        $state->setName(ucfirst(str_replace('_', ' ', $technicalName)));
        $state->setTranslated(['name' => ucfirst(str_replace('_', ' ', $technicalName))]);

        return $state;
    }
}
