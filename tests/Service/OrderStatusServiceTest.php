<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Event\OrderStatusResponseEvent;
use Emporiqa\ShopwarePlugin\Service\ChannelResolverInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\OrderStatusService;
use Emporiqa\ShopwarePlugin\Tests\Support\EntityCollectionHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
        $this->assertSame('10001', $data['order_number']);
        $this->assertSame('Anna Schmidt', $data['customer_name']);
        $this->assertSame('DHL', $data['shipping_method']);
        $this->assertSame('Paid', $data['payment_status']);
        $this->assertSame(['shipping' => 4.9, 'tax' => 8.77, 'total' => 54.9], array_diff_key($data['totals'], ['subtotal' => 0]));
    }

    /**
     * Each position is an item in the order's currency and price mode, with
     * its variant options; a negative position (promotion, credit) is the
     * discount, and a container's children are not listed again.
     */
    public function testItemsTotalsAndDiscount(): void
    {
        $order = $this->order(self::CUSTOMER_A, 'a@shop.test', 'open', 'paid', 'shipped');
        $currency = new CurrencyEntity();
        $currency->setId(Uuid::randomHex());
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        $shirt = $this->lineItem('product', 'Emporiqa test shirt', 2, 19.99, 1, ['productNumber' => 'SW-1.2', 'options' => [
            ['group' => 'Colour', 'option' => 'Blue'],
            ['group' => 'Size', 'option' => 'XL'],
        ]]);
        $bundle = $this->lineItem('container', 'Emporiqa test bundle', 1, 10.0, 2);
        $child = $this->lineItem('product', 'Emporiqa test bundle part', 1, 10.0, 1, ['productNumber' => 'SW-3']);
        $child->setParentId($bundle->getId());
        $promotion = $this->lineItem('promotion', '10% off', 1, -5.0, 3);
        $order->setLineItems(new OrderLineItemCollection([$promotion, $child, $bundle, $shirt]));
        $this->returnOrders($order);

        $result = $this->service->handle(['fields' => ['order_number' => '10001', 'email' => 'a@shop.test']], Context::createDefaultContext(), self::STORE);

        $data = $result['data'];
        $this->assertSame('EUR', $data['currency']);
        $this->assertSame([
            ['name' => 'Emporiqa test shirt', 'sku' => 'SW-1.2', 'quantity' => 2, 'unit_price' => 19.99, 'total_price' => 39.98, 'variant' => 'Blue / XL'],
            ['name' => 'Emporiqa test bundle', 'quantity' => 1, 'unit_price' => 10.0, 'total_price' => 10.0],
        ], $data['items']);
        $this->assertSame(['subtotal' => 49.98, 'shipping' => 4.9, 'tax' => 8.77, 'discount' => 5.0, 'total' => 54.9], $data['totals']);
    }

    public function testAddressesPaymentAndDeliveryTime(): void
    {
        $order = $this->order(self::CUSTOMER_A, 'a@shop.test', 'open', 'paid', 'open');
        $billing = $this->address('Anna', 'Schmidt', 'Hauptstr. 1', 'Berlin', 'Germany');
        $billing->setCompany('Emporiqa test GmbH');
        $billing->setPhoneNumber('+49 30 1234567');
        $order->setBillingAddressId($billing->getId());
        $order->setAddresses(new OrderAddressCollection([$billing]));

        $shipping = $this->address('Ben', 'Meyer', 'Ringstr. 5', 'Hamburg', 'Germany');
        $shipping->setAdditionalAddressLine1('Hinterhaus');
        $shipping->setZipcode('20095');
        $delivery = $order->getDeliveries()?->first();
        $this->assertNotNull($delivery);
        $delivery->setShippingOrderAddress($shipping);
        $deliveryTime = new DeliveryTimeEntity();
        $deliveryTime->setId(Uuid::randomHex());
        $deliveryTime->setTranslated(['name' => '1-3 days']);
        $delivery->getShippingMethod()?->setDeliveryTime($deliveryTime);

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(Uuid::randomHex());
        $paymentMethod->setTranslated(['name' => 'Invoice']);
        $order->getTransactions()?->first()?->setPaymentMethod($paymentMethod);
        $this->returnOrders($order);

        $result = $this->service->handle(['fields' => ['order_number' => '10001', 'email' => 'a@shop.test']], Context::createDefaultContext(), self::STORE);

        $data = $result['data'];
        $this->assertSame('Invoice', $data['payment_method']);
        $this->assertSame('Paid', $data['payment_status']);
        $this->assertSame('1-3 days', $data['delivery_time']);
        $this->assertSame([
            'name' => 'Ben Meyer', 'address1' => 'Ringstr. 5', 'address2' => 'Hinterhaus',
            'postcode' => '20095', 'city' => 'Hamburg', 'country' => 'Germany',
        ], $data['shipping_address']);
        $this->assertSame([
            'name' => 'Anna Schmidt', 'company' => 'Emporiqa test GmbH', 'address1' => 'Hauptstr. 1',
            'city' => 'Berlin', 'country' => 'Germany', 'phone' => '+49 30 1234567',
        ], $data['billing_address']);
    }

    /**
     * An order without a delivery (a digital one) has no shipping address,
     * method or delivery time; nothing is filled in from elsewhere.
     */
    public function testAMissingAddressIsLeftOut(): void
    {
        $this->returnOrders($this->order(self::CUSTOMER_A, 'a@shop.test', 'open', 'paid', null));

        $data = $this->service->handle(['fields' => ['order_number' => '10001', 'email' => 'a@shop.test']], Context::createDefaultContext(), self::STORE)['data'];

        foreach (['shipping_address', 'billing_address', 'shipping_method', 'delivery_time', 'items', 'currency'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
    }

    /**
     * The answer never echoes the email, the customer id or an internal id.
     */
    public function testNoEmailOrInternalIdsInTheAnswer(): void
    {
        $order = $this->order(self::CUSTOMER_A, 'a@shop.test', 'open', 'paid', 'shipped', ['TRACK1']);
        $order->setLineItems(new OrderLineItemCollection([$this->lineItem('product', 'Emporiqa test shirt', 1, 10.0, 1, ['productNumber' => 'SW-1'])]));
        $this->returnOrders($order);

        $json = (string) json_encode($this->service->handle(['fields' => ['order_number' => '10001', 'email' => 'a@shop.test']], Context::createDefaultContext(), self::STORE));

        $this->assertStringNotContainsString('a@shop.test', $json);
        $this->assertStringNotContainsString(self::CUSTOMER_A, $json);
        $this->assertStringNotContainsString($order->getId(), $json);
    }

    /**
     * The event runs after `data` is filled: a listener sees the full answer,
     * can change a key and add its own fields under `extra`.
     */
    public function testAListenerAddsExtraAfterTheDataIsFilled(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(OrderStatusResponseEvent::class, function (OrderStatusResponseEvent $event) use (&$seen): void {
            $data = $event->getData();
            $seen = array_keys($data);
            $data['extra'] = ['gift_wrap' => true, 'pickup_point' => 'Emporiqa test store'];
            $data['shipping_method'] = 'Express';
            $event->setData($data);
        });
        $service = new OrderStatusService($this->orderRepository, $this->config, $this->createMock(ChannelResolverInterface::class), $dispatcher);
        $this->returnOrders($this->order(self::CUSTOMER_A, 'a@shop.test', 'open', 'paid', 'shipped'));

        $data = $service->handle(['fields' => ['order_number' => '10001', 'email' => 'a@shop.test']], Context::createDefaultContext(), self::STORE)['data'];

        $this->assertContains('totals', $seen);
        $this->assertContains('customer_name', $seen);
        $this->assertSame(['gift_wrap' => true, 'pickup_point' => 'Emporiqa test store'], $data['extra']);
        $this->assertSame('Express', $data['shipping_method']);
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
        $order->setAmountTotal(54.9);
        $order->setAmountNet(46.13);
        $order->setShippingTotal(4.9);

        $orderCustomer = new OrderCustomerEntity();
        $orderCustomer->setId(Uuid::randomHex());
        $orderCustomer->setEmail($email);
        $orderCustomer->setFirstName('Anna');
        $orderCustomer->setLastName('Schmidt');
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

    /**
     * @param array<string, mixed> $payload
     */
    private function lineItem(string $type, string $label, int $quantity, float $unitPrice, int $position, array $payload = []): OrderLineItemEntity
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId(Uuid::randomHex());
        $lineItem->setType($type);
        $lineItem->setLabel($label);
        $lineItem->setQuantity($quantity);
        $lineItem->setUnitPrice($unitPrice);
        $lineItem->setTotalPrice(round($unitPrice * $quantity, 2));
        $lineItem->setPosition($position);
        $lineItem->setPayload($payload);

        return $lineItem;
    }

    private function address(string $first, string $last, string $street, string $city, string $countryName): OrderAddressEntity
    {
        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());
        $country->setTranslated(['name' => $countryName]);
        $address = new OrderAddressEntity();
        $address->setId(Uuid::randomHex());
        $address->setFirstName($first);
        $address->setLastName($last);
        $address->setStreet($street);
        $address->setCity($city);
        // The DAL always hydrates it; Shopware 6.6 declares it without a default.
        $address->setZipcode(null);
        $address->setCountry($country);

        return $address;
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
