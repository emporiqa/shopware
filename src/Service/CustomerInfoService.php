<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\Event\CustomerInfoResponseEvent;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The `customer_info` ready-made rule: who the signed-in shopper is.
 *
 * The name and email come from the customer account, never from an order;
 * the orders are the account's own (by customer id, so a guest order placed
 * with the same email is not one of them), in the sales channels this
 * Emporiqa store syncs. Read-only; nothing about any other customer.
 */
class CustomerInfoService
{
    public const MAX_ORDERS = 10;

    /** The platform keeps names and labels to 200 characters, order numbers to 64. */
    private const MAX_TEXT = 200;

    private const MAX_ORDER_NUMBER = 64;

    /**
     * @param EntityRepository<CustomerCollection> $customerRepository
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $orderRepository,
        private readonly ConfigServiceInterface $config,
        private readonly SyncServiceInterface $syncService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $payload decoded, signature-verified request body
     * @param string $storeId the Emporiqa store that signed the request
     *
     * @return array<string, mixed> response envelope
     */
    public function handle(array $payload, Context $context, string $storeId): array
    {
        $customerId = CustomerPriceService::customerId($payload);
        if ($customerId === null) {
            return ['status' => 'rejected', 'message_code' => 'missing_field'];
        }
        if (!Uuid::isValid($customerId)) {
            return ['status' => 'not_found'];
        }

        $salesChannelIds = $this->salesChannelIds($storeId);
        $customer = $salesChannelIds === [] ? null : $this->loadCustomer($customerId, $salesChannelIds, $context);
        if ($customer === null) {
            return ['status' => 'not_found'];
        }

        $orders = $this->loadOrders($customerId, $salesChannelIds, $context);
        $statuses = [];
        foreach ($orders as $order) {
            $statuses[$order->getId()] = OrderStatusService::statusCode(
                $order->getStateMachineState(),
                OrderStatusService::latestTransaction($order)?->getStateMachineState(),
                $order->getDeliveries()?->first()?->getStateMachineState(),
            );
        }
        $labels = $this->stateNames(array_filter(array_map(static fn (array $status): ?string => $status[1]?->getId(), $statuses)));

        $data = ['customer' => $this->customerData($customer), 'orders' => []];
        foreach ($orders as $order) {
            $data['orders'][] = $this->orderData($order, $statuses[$order->getId()], $labels);
        }

        $event = new CustomerInfoResponseEvent($customer, $orders, $data);
        $this->eventDispatcher->dispatch($event);

        return ['status' => 'found', 'data' => $event->getData()];
    }

    /**
     * The sales channels synced to this Emporiqa store: active, not headless,
     * ticked, with a storefront domain, as the sync decides them.
     *
     * @return list<string>
     */
    private function salesChannelIds(string $storeId): array
    {
        $ids = [];
        foreach ($this->syncService->buildChannelContexts() as $contexts) {
            foreach ($contexts as $ctx) {
                $id = $ctx['salesChannelId'] ?? '';
                if ($id !== '' && $this->config->getStoreId($id) === $storeId) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * An active, registered account (a guest checkout's record is not one)
     * that may sign in on one of these sales channels.
     *
     * @param list<string> $salesChannelIds
     */
    private function loadCustomer(string $customerId, array $salesChannelIds, Context $context): ?CustomerEntity
    {
        $criteria = new Criteria([$customerId]);
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('guest', false));
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('boundSalesChannelId', null),
            new EqualsAnyFilter('boundSalesChannelId', $salesChannelIds),
        ]));

        $customer = $this->customerRepository->search($criteria, $context)->getEntities()->first();

        return $customer instanceof CustomerEntity ? $customer : null;
    }

    /**
     * The account's newest placed orders. The live version only (the
     * repository's default), so an order being edited in the Administration
     * is shown as placed, not as its unsaved draft.
     *
     * @param list<string> $salesChannelIds
     */
    private function loadOrders(string $customerId, array $salesChannelIds, Context $context): OrderCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderCustomer.customerId', $customerId));
        $criteria->addFilter(new EqualsAnyFilter('salesChannelId', $salesChannelIds));
        $criteria->addSorting(new FieldSorting('orderDateTime', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(self::MAX_ORDERS);
        $criteria->addAssociation('currency');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('deliveries.stateMachineState');

        return $this->orderRepository->search($criteria, $context)->getEntities();
    }

    /**
     * @return array<string, string>
     */
    private function customerData(CustomerEntity $customer): array
    {
        $first = self::text($customer->getFirstName());
        $last = self::text($customer->getLastName());
        $data = [
            'name' => self::text(trim($first . ' ' . $last)),
            'first_name' => $first,
            'last_name' => $last,
            'email' => mb_substr(trim($customer->getEmail()), 0, 254),
        ];

        return array_filter($data, static fn (string $value): bool => $value !== '');
    }

    /**
     * @param array{0: string, 1: ?StateMachineStateEntity} $status from OrderStatusService::statusCode()
     * @param array<string, array<string, string>> $labels state id => language id => name
     *
     * @return array<string, mixed>
     */
    private function orderData(OrderEntity $order, array $status, array $labels): array
    {
        [$code, $labelState] = $status;
        $label = '';
        if ($labelState !== null) {
            $names = $labels[$labelState->getId()] ?? [];
            // The order's language, else the shop's default, else the request's.
            $label = $names[$order->getLanguageId()] ?? $names[Defaults::LANGUAGE_SYSTEM] ?? ($labelState->getTranslation('name') ?? $labelState->getName());
        }

        $data = [
            'order_number' => mb_substr(trim((string) $order->getOrderNumber()), 0, self::MAX_ORDER_NUMBER),
            'placed_at' => $order->getOrderDateTime()->format('c'),
            'status_code' => $code,
            'status_label' => self::text($label),
            'total' => round($order->getAmountTotal(), $order->getItemRounding()?->getDecimals() ?? 2),
            'currency' => (string) $order->getCurrency()?->getIsoCode(),
        ];
        // Emporiqa keeps totals from 0 up and would drop the whole order for a
        // negative one (a manual credit); the order is listed without it.
        if ($data['total'] < 0) {
            unset($data['total']);
        }

        return array_filter($data, static fn (mixed $value): bool => $value !== '');
    }

    /**
     * Every language's name of these states, in one query: each order is
     * labelled in its own language, which the request's context is not.
     *
     * @param array<string, string> $stateIds
     *
     * @return array<string, array<string, string>> state id => language id => name
     */
    private function stateNames(array $stateIds): array
    {
        if ($stateIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`state_machine_state_id`)) AS `state`, LOWER(HEX(`language_id`)) AS `language`, `name`'
            . ' FROM `state_machine_state_translation` WHERE `state_machine_state_id` IN (:ids)',
            ['ids' => array_map(Uuid::fromHexToBytes(...), array_values(array_unique($stateIds)))],
            ['ids' => ArrayParameterType::BINARY],
        );

        $names = [];
        foreach ($rows as $row) {
            if (\is_string($row['name']) && $row['name'] !== '') {
                $names[(string) $row['state']][(string) $row['language']] = $row['name'];
            }
        }

        return $names;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? mb_substr(trim($value), 0, self::MAX_TEXT) : '';
    }
}
