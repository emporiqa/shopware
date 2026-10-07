<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\Event\CustomerInfoResponseEvent;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\CustomerInfoService;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The repositories here apply the service's own criteria (filters, sorting,
 * limit) to in-memory entities, so what is checked is what the shop would
 * answer, not only how the query looks.
 */
class CustomerInfoServiceTest extends TestCase
{
    private const STORE = 'st_1';
    private const ADA = '0190aaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const BOB = '0190bbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ADA_GUEST = '0190cccccccccccccccccccccccccccc';
    private const EN = '2fbb5fe2e29a4d70aa5854ce7ce3e20b';
    private const DE = '0190dddddddddddddddddddddddddddd';

    /** @var list<CustomerEntity> */
    private array $customers = [];

    /** @var list<OrderEntity> */
    private array $orders = [];

    private EventDispatcher $dispatcher;

    /** @var array<string, array<string, string>> state id => language id => name, as state_machine_state_translation holds them */
    private array $stateNames = [];

    private CustomerInfoService $service;

    protected function setUp(): void
    {
        $config = $this->createMock(ConfigServiceInterface::class);
        $config->method('getStoreId')->willReturnCallback(fn (?string $id) => $id === 'sc-other-store' ? 'st_2' : self::STORE);
        $sync = $this->createMock(SyncServiceInterface::class);
        // Headless and unticked channels are already left out here, as the sync leaves them out.
        $sync->method('buildChannelContexts')->willReturn([
            'storefront' => [['salesChannelId' => 'sc-1', 'languageCode' => 'en-GB'], ['salesChannelId' => 'sc-1', 'languageCode' => 'de-DE']],
            'b2b' => [['salesChannelId' => 'sc-b2b', 'languageCode' => 'en-GB']],
            'other' => [['salesChannelId' => 'sc-other-store', 'languageCode' => 'en-GB']],
        ]);
        $this->dispatcher = new EventDispatcher();

        $this->customers = [
            $this->customer(self::ADA, 'Ada', 'Lovelace', 'ada@example.com'),
            $this->customer(self::BOB, 'Bob', 'Builder', 'bob@example.com'),
            $this->customer(self::ADA_GUEST, 'Ada', 'Lovelace', 'ada@example.com', guest: true),
        ];

        $this->service = new CustomerInfoService(
            $this->repository(fn () => $this->customers, CustomerCollection::class),
            $this->repository(fn () => $this->orders, OrderCollection::class),
            $config,
            $sync,
            $this->dispatcher,
            $this->translationTable(),
        );
    }

    public function testNoCustomerIsRejectedWithoutALookup(): void
    {
        $this->assertSame(['status' => 'rejected', 'message_code' => 'missing_field'], $this->handle(null));
        $this->assertSame(['status' => 'rejected', 'message_code' => 'missing_field'], $this->service->handle(['customer' => null], Context::createDefaultContext(), self::STORE));
    }

    public function testAnUnknownOrMalformedIdIsNotFound(): void
    {
        $this->assertSame(['status' => 'not_found'], $this->handle('0190eeeeeeeeeeeeeeeeeeeeeeeeeeee'));
        $this->assertSame(['status' => 'not_found'], $this->handle('77'));
    }

    /**
     * A guest checkout creates a customer record too; it is no account, so
     * the answer is the same as for an unknown id.
     */
    public function testAGuestRecordIsNotFound(): void
    {
        $this->assertSame(['status' => 'not_found'], $this->handle(self::ADA_GUEST));
    }

    public function testAnInactiveAccountOrOneBoundToAnotherStoresChannelIsNotFound(): void
    {
        $this->customers[] = $inactive = $this->customer('0190ffffffffffffffffffffffffffff', 'In', 'Active', 'in@example.com');
        $inactive->setActive(false);
        $this->customers[] = $bound = $this->customer('0190abababababababababababab0001', 'Bo', 'Und', 'bound@example.com');
        $bound->setBoundSalesChannelId('sc-other-store');

        $this->assertSame(['status' => 'not_found'], $this->handle($inactive->getId()));
        $this->assertSame(['status' => 'not_found'], $this->handle($bound->getId()));
    }

    public function testTheAccountsDetailsComeFromTheAccount(): void
    {
        // An order placed under another name and email does not change them.
        $this->orders[] = $this->order('10004', self::ADA, '2026-10-01 10:00:00', orderEmail: 'other@example.com');

        $result = $this->handle(strtoupper(self::ADA));

        $this->assertSame('found', $result['status']);
        $this->assertSame(
            ['name' => 'Ada Lovelace', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com'],
            $result['data']['customer'],
        );
    }

    public function testEmptyNamePartsAreLeftOut(): void
    {
        $this->customers[] = $this->customer('0190abababababababababababab0002', '', 'Solo', 'solo@example.com');

        $result = $this->handle('0190abababababababababababab0002');

        $this->assertSame(['name' => 'Solo', 'last_name' => 'Solo', 'email' => 'solo@example.com'], $result['data']['customer']);
    }

    /**
     * Only the account's own orders: never another customer's, and never a
     * guest order placed with the same email (another customer id).
     */
    public function testOnlyTheAccountsOwnOrdersAreListed(): void
    {
        $this->orders[] = $this->order('10004', self::ADA, '2026-10-02 09:00:00');
        $this->orders[] = $this->order('10005', self::BOB, '2026-10-03 09:00:00');
        $this->orders[] = $this->order('10001', self::ADA_GUEST, '2026-10-04 09:00:00', orderEmail: 'ada@example.com');

        $result = $this->handle(self::ADA);

        $this->assertSame(['10004'], array_column($result['data']['orders'], 'order_number'));
    }

    public function testOnlyOrdersOfTheStoresSyncedSalesChannels(): void
    {
        $this->orders[] = $this->order('10004', self::ADA, '2026-10-02 09:00:00', salesChannelId: 'sc-1');
        $this->orders[] = $this->order('20001', self::ADA, '2026-10-03 09:00:00', salesChannelId: 'sc-b2b');
        $this->orders[] = $this->order('30001', self::ADA, '2026-10-04 09:00:00', salesChannelId: 'sc-other-store');
        $this->orders[] = $this->order('40001', self::ADA, '2026-10-05 09:00:00', salesChannelId: 'sc-headless');

        $result = $this->handle(self::ADA);

        $this->assertSame(['20001', '10004'], array_column($result['data']['orders'], 'order_number'));
    }

    public function testTheNewestTenOrdersNewestFirst(): void
    {
        for ($i = 1; $i <= 12; ++$i) {
            $this->orders[] = $this->order((string) (10000 + $i), self::ADA, sprintf('2026-09-%02d 12:00:00', $i));
        }

        $orders = $this->handle(self::ADA)['data']['orders'];

        $this->assertCount(CustomerInfoService::MAX_ORDERS, $orders);
        $this->assertSame('10012', $orders[0]['order_number']);
        $this->assertSame('10003', $orders[9]['order_number']);
    }

    public function testNoOrdersIsAnEmptyList(): void
    {
        $this->assertSame([], $this->handle(self::BOB)['data']['orders']);
    }

    public function testAnOrderIsAnsweredAsOrderStatusAnswersIt(): void
    {
        $order = $this->order('10004', self::ADA, '2026-10-02 09:00:00', languageId: self::DE, total: 1234.567, currency: 'CHF');
        $delivery = new OrderDeliveryEntity();
        $delivery->setId('d-1');
        $delivery->setStateMachineState($this->state('shipped', ['Shipped', 'Versandt']));
        $order->setDeliveries(new OrderDeliveryCollection([$delivery]));
        $this->orders[] = $order;

        $this->assertSame([
            'order_number' => '10004',
            'placed_at' => '2026-10-02T09:00:00+00:00',
            'status_code' => 'shipped',
            'status_label' => 'Versandt',
            'total' => 1234.57,
            'currency' => 'CHF',
        ], $this->handle(self::ADA)['data']['orders'][0]);
    }

    public function testTheLabelIsInTheOrdersLanguage(): void
    {
        $this->orders[] = $this->order('10004', self::ADA, '2026-10-02 09:00:00', languageId: self::EN);
        $this->orders[] = $this->order('10006', self::ADA, '2026-10-03 09:00:00', languageId: self::DE);

        $orders = $this->handle(self::ADA)['data']['orders'];

        $this->assertSame(['Offen', 'Open'], array_column($orders, 'status_label'));
        $this->assertSame(['pending', 'pending'], array_column($orders, 'status_code'));
    }

    public function testTheLatestPaymentDecidesAsInOrderStatus(): void
    {
        $order = $this->order('10004', self::ADA, '2026-10-02 09:00:00', languageId: self::DE);
        $old = new OrderTransactionEntity();
        $old->setId('t-1');
        $old->setCreatedAt(new \DateTimeImmutable('2026-10-02 09:00:00'));
        $old->setStateMachineState($this->state('paid', ['Paid', 'Bezahlt']));
        $refund = new OrderTransactionEntity();
        $refund->setId('t-2');
        $refund->setCreatedAt(new \DateTimeImmutable('2026-10-03 09:00:00'));
        $refund->setStateMachineState($this->state('refunded', ['Refunded', 'Erstattet']));
        $order->setTransactions(new OrderTransactionCollection([$old, $refund]));
        $this->orders[] = $order;

        $first = $this->handle(self::ADA)['data']['orders'][0];

        $this->assertSame(['refunded', 'Erstattet'], [$first['status_code'], $first['status_label']]);
    }

    /**
     * The platform keeps totals from 0 up and would drop the whole order for
     * a negative one; the order is listed without its total instead.
     */
    public function testANegativeTotalIsLeftOutNotTheOrder(): void
    {
        $this->orders[] = $this->order('10004', self::ADA, '2026-10-02 09:00:00', total: -15.0);

        $order = $this->handle(self::ADA)['data']['orders'][0];

        $this->assertSame('10004', $order['order_number']);
        $this->assertArrayNotHasKey('total', $order);
    }

    public function testACancelledOrderIsStillListed(): void
    {
        $order = $this->order('10004', self::ADA, '2026-10-02 09:00:00');
        $order->setStateMachineState($this->state('cancelled', ['Cancelled', 'Abgebrochen']));
        $this->orders[] = $order;

        $this->assertSame('cancelled', $this->handle(self::ADA)['data']['orders'][0]['status_code']);
    }

    /**
     * CustomerInfoResponseEvent runs after the data is built: a merchant can
     * remove what they do not want to share and add `extra`.
     */
    public function testTheEventCanChangeTheAnswer(): void
    {
        $this->orders[] = $this->order('10004', self::ADA, '2026-10-02 09:00:00');
        $seen = null;
        $this->dispatcher->addListener(CustomerInfoResponseEvent::class, function (CustomerInfoResponseEvent $event) use (&$seen): void {
            $seen = [$event->getCustomer()->getId(), $event->getOrders()->count()];
            $data = $event->getData();
            unset($data['customer']['email']);
            $data['extra'] = ['loyalty_tier' => 'Gold'];
            $event->setData($data);
        });

        $result = $this->handle(self::ADA);

        $this->assertSame([self::ADA, 1], $seen);
        $this->assertArrayNotHasKey('email', $result['data']['customer']);
        $this->assertSame(['loyalty_tier' => 'Gold'], $result['data']['extra']);
        $this->assertSame('10004', $result['data']['orders'][0]['order_number']);
    }

    public function testNothingButTheContractFields(): void
    {
        $this->orders[] = $this->order('10004', self::ADA, '2026-10-02 09:00:00');

        $data = $this->handle(self::ADA)['data'];

        $this->assertSame(['customer', 'orders'], array_keys($data));
        $this->assertSame(['order_number', 'placed_at', 'status_code', 'status_label', 'total', 'currency'], array_keys($data['orders'][0]));
    }

    public function testAStoreWithoutSyncedSalesChannelsAnswersNotFound(): void
    {
        $this->assertSame(['status' => 'not_found'], $this->service->handle(['customer' => ['id' => self::ADA]], Context::createDefaultContext(), 'st_unknown_has_no_channels'));
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(?string $customerId): array
    {
        $payload = ['rule' => 'customer_info', 'request_id' => 'r-1'];
        if ($customerId !== null) {
            $payload['customer'] = ['id' => $customerId];
        }

        return $this->service->handle($payload, Context::createDefaultContext(), self::STORE);
    }

    private function customer(string $id, string $first, string $last, string $email, bool $guest = false): CustomerEntity
    {
        $customer = new CustomerEntity();
        $customer->setId($id);
        $customer->setFirstName($first);
        $customer->setLastName($last);
        $customer->setEmail($email);
        $customer->setGuest($guest);
        $customer->setActive(true);

        return $customer;
    }

    private function order(
        string $number,
        string $customerId,
        string $placedAt,
        string $salesChannelId = 'sc-1',
        string $orderEmail = 'ada@example.com',
        string $languageId = self::EN,
        float $total = 49.9,
        string $currency = 'EUR',
    ): OrderEntity {
        $orderCustomer = new OrderCustomerEntity();
        $orderCustomer->setId('oc-' . $number);
        $orderCustomer->setCustomerId($customerId);
        $orderCustomer->setEmail($orderEmail);

        $currencyEntity = new CurrencyEntity();
        $currencyEntity->setId('cur-' . $currency);
        $currencyEntity->setIsoCode($currency);

        $order = new OrderEntity();
        $order->setId('order-' . $number);
        $order->setOrderNumber($number);
        $order->setOrderDateTime(new \DateTimeImmutable($placedAt, new \DateTimeZone('UTC')));
        $order->setSalesChannelId($salesChannelId);
        $order->setLanguageId($languageId);
        $order->setOrderCustomer($orderCustomer);
        $order->setAmountTotal($total);
        $order->setCurrency($currencyEntity);
        $order->setItemRounding(new CashRoundingConfig(2, 0.01, true));
        $order->setStateMachineState($this->state('open', ['Open', 'Offen']));
        $order->setTransactions(new OrderTransactionCollection([]));
        $order->setDeliveries(new OrderDeliveryCollection([]));

        return $order;
    }

    /**
     * @param array{0: string, 1: string} $names English and German name
     */
    private function state(string $technicalName, array $names): StateMachineStateEntity
    {
        $state = new StateMachineStateEntity();
        $state->setId(md5('state-' . $technicalName));
        $state->setTechnicalName($technicalName);
        // What the request's context resolves; the order's own language must win over it.
        $state->setName('context:' . $names[0]);
        $this->stateNames[$state->getId()] = [self::EN => $names[0], self::DE => $names[1]];

        return $state;
    }

    private function translationTable(): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params): array {
            $this->assertStringContainsString('FROM `state_machine_state_translation`', $sql);
            $rows = [];
            foreach ($params['ids'] as $bytes) {
                foreach ($this->stateNames[bin2hex($bytes)] ?? [] as $languageId => $name) {
                    $rows[] = ['state' => bin2hex($bytes), 'language' => $languageId, 'name' => $name];
                }
            }

            return $rows;
        });

        return $connection;
    }

    /**
     * @param \Closure(): list<Entity> $rows
     * @param class-string<EntityCollection<Entity>> $collectionClass
     */
    private function repository(\Closure $rows, string $collectionClass): EntityRepository&MockObject
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(function (Criteria $criteria) use ($rows, $collectionClass) {
            $matches = array_values(array_filter($rows(), function (Entity $row) use ($criteria): bool {
                if ($criteria->getIds() !== [] && !\in_array($row->getUniqueIdentifier(), $criteria->getIds(), true)) {
                    return false;
                }
                foreach ($criteria->getFilters() as $filter) {
                    if (!self::filterMatches($filter, $row)) {
                        return false;
                    }
                }

                return true;
            }));
            foreach (array_reverse($criteria->getSorting()) as $sorting) {
                usort($matches, function (Entity $a, Entity $b) use ($sorting): int {
                    $cmp = self::value($a, $sorting->getField()) <=> self::value($b, $sorting->getField());

                    return $sorting->getDirection() === FieldSorting::DESCENDING ? -$cmp : $cmp;
                });
            }
            if ($criteria->getLimit() !== null) {
                $matches = \array_slice($matches, 0, $criteria->getLimit());
            }

            $collection = new $collectionClass($matches);
            $result = $this->createMock(EntitySearchResult::class);
            $result->method('getEntities')->willReturn($collection);

            return $result;
        });

        return $repository;
    }

    private static function filterMatches(Filter $filter, Entity $row): bool
    {
        if ($filter instanceof MultiFilter) {
            $results = array_map(fn (Filter $query) => self::filterMatches($query, $row), $filter->getQueries());

            return $filter->getOperator() === MultiFilter::CONNECTION_OR ? \in_array(true, $results, true) : !\in_array(false, $results, true);
        }
        if ($filter instanceof EqualsFilter) {
            return self::value($row, $filter->getField()) === $filter->getValue();
        }
        if ($filter instanceof EqualsAnyFilter) {
            return \in_array(self::value($row, $filter->getField()), $filter->getValue(), true);
        }

        throw new \LogicException('Filter not modelled in this test: ' . $filter::class);
    }

    private static function value(object $row, string $path): mixed
    {
        foreach (explode('.', $path) as $field) {
            if ($row === null) {
                return null;
            }
            $getter = method_exists($row, 'get' . ucfirst($field)) ? 'get' . ucfirst($field) : 'is' . ucfirst($field);
            if ($field === 'guest') {
                $getter = 'getGuest';
            }
            $row = $row->$getter();
        }

        return $row instanceof \DateTimeInterface ? $row->format('U.u') : $row;
    }
}
