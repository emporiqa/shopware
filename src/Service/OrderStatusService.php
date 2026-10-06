<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Emporiqa\ShopwarePlugin\Event\OrderStatusResponseEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The `order_status` ready-made rule: request fields in, response envelope out.
 *
 * Read-only. "No such order" and "the email or customer does not match" are
 * one answer on purpose, and required fields are checked before the lookup,
 * so the answer never reveals whether an order exists.
 */
class OrderStatusService
{
    private const MAX_TRACKING = 10;

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly ConfigServiceInterface $config,
        private readonly ChannelResolverInterface $channelResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * A request field as a trimmed string, '' when missing or not scalar.
     *
     * @param array<string, mixed> $payload
     */
    public static function field(array $payload, string $name): string
    {
        $value = \is_array($payload['fields'] ?? null) ? ($payload['fields'][$name] ?? null) : null;

        return \is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $payload decoded, signature-verified request body
     * @param string $storeId the Emporiqa store that signed the request
     *
     * @return array<string, mixed> response envelope
     */
    public function handle(array $payload, Context $context, string $storeId): array
    {
        $customerId = '';
        $customer = $payload['customer'] ?? null;
        if (\is_array($customer) && \is_string($customer['id'] ?? null)) {
            $customerId = strtolower(trim($customer['id']));
        }

        $orderNumber = self::field($payload, 'order_number');
        $email = self::field($payload, 'email');

        // The email proves the order unless a verified customer stands in for it.
        $missing = [];
        if ($orderNumber === '') {
            $missing[] = 'order_number';
        }
        if ($email === '' && $customerId === '') {
            $missing[] = 'email';
        }
        if ($missing !== []) {
            return ['status' => 'rejected', 'ask' => $missing, 'message_code' => 'missing_field'];
        }
        if (
            mb_strlen($orderNumber) > 64
            || preg_match('/[\s\x00-\x1f\x7f]/u', $orderNumber)
            || ($email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL) === false)
        ) {
            return ['status' => 'rejected', 'message_code' => 'invalid_field'];
        }

        $order = $this->findOrder($orderNumber, $email, $customerId, $storeId, $context);
        if ($order === null) {
            return ['status' => 'not_found'];
        }

        $event = new OrderStatusResponseEvent($order, $this->buildData($order));
        $this->eventDispatcher->dispatch($event);

        return ['status' => 'found', 'data' => $event->getData()];
    }

    /**
     * The newest order with this number, in a synced sales channel of the
     * signing store, that the caller owns. Number ranges can be per sales
     * channel, so one number may match several orders.
     */
    private function findOrder(string $orderNumber, string $email, string $customerId, string $storeId, Context $context): ?OrderEntity
    {
        $salesChannelIds = $this->config->getEnabledSalesChannels();
        if ($salesChannelIds === []) {
            $salesChannelIds = array_map('strval', array_keys($this->channelResolver->getMapping()));
        }
        $salesChannelIds = array_values(array_filter(
            $salesChannelIds,
            fn (string $id): bool => $this->config->getStoreId($id) === $storeId,
        ));
        if ($salesChannelIds === []) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));
        $criteria->addFilter(new EqualsAnyFilter('salesChannelId', $salesChannelIds));
        $criteria->addAssociation('orderCustomer');
        $criteria->addSorting(new FieldSorting('orderDateTime', FieldSorting::DESCENDING));
        $criteria->setLimit(10);

        $owned = null;
        $candidates = $this->orderRepository->search($criteria, $context)->getEntities();
        foreach ($candidates as $candidate) {
            if ($this->ownedBy($candidate, $email, $customerId)) {
                $owned = $candidate;
                break;
            }
        }
        if ($owned === null) {
            return null;
        }

        // Labels in the order's own language, as the legacy endpoint does.
        $fetchContext = $context;
        if ($owned->getLanguageId() !== $context->getLanguageId()) {
            $fetchContext = new Context(
                $context->getSource(),
                languageIdChain: [$owned->getLanguageId(), Defaults::LANGUAGE_SYSTEM],
            );
        }

        $full = new Criteria([$owned->getId()]);
        $full->addAssociation('stateMachineState');
        $full->addAssociation('transactions.stateMachineState');
        $full->addAssociation('deliveries.stateMachineState');
        $full->addAssociation('deliveries.shippingMethod');
        $order = $this->orderRepository->search($full, $fetchContext)->getEntities()->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    /**
     * With an email, the order's email must match it, whoever is signed in:
     * a signed-in shopper looking up a guest order they placed proves it the
     * same way as when signed out. Without one, the verified customer must be
     * the order's customer (by customer id, never by account email).
     */
    private function ownedBy(OrderEntity $order, string $email, string $customerId): bool
    {
        $orderCustomer = $order->getOrderCustomer();
        if ($orderCustomer === null) {
            return false;
        }
        if ($email === '') {
            $orderCustomerId = strtolower((string) $orderCustomer->getCustomerId());

            return $customerId !== '' && $orderCustomerId !== '' && hash_equals($orderCustomerId, $customerId);
        }

        return mb_strtolower(trim($orderCustomer->getEmail())) === mb_strtolower($email);
    }

    /**
     * `data` per the catalog schema: status_code, status_label, placed_at,
     * tracking, estimated_delivery.
     *
     * @return array<string, mixed>
     */
    private function buildData(OrderEntity $order): array
    {
        $orderState = $order->getStateMachineState();
        $transaction = $this->latestTransaction($order);
        $deliveries = $order->getDeliveries();
        $delivery = $deliveries?->first();

        [$code, $labelState] = $this->statusCode(
            $orderState,
            $transaction?->getStateMachineState(),
            $delivery?->getStateMachineState(),
        );

        $data = [
            'status_code' => $code,
            'status_label' => $labelState !== null ? $this->stateName($labelState) : '',
            'placed_at' => $order->getOrderDateTime()->format('c'),
            'tracking' => [],
        ];

        $seen = [];
        foreach ($deliveries ?? [] as $each) {
            foreach ($this->trackingEntries($each) as $entry) {
                if (!isset($seen[$entry['number']]) && \count($data['tracking']) < self::MAX_TRACKING) {
                    $seen[$entry['number']] = true;
                    $data['tracking'][] = $entry;
                }
            }
        }

        $latest = $delivery?->getShippingDateLatest();
        if ($latest !== null && \in_array($code, ['pending', 'pending_payment', 'processing', 'on_hold', 'partially_shipped', 'shipped'], true)) {
            $data['estimated_delivery'] = $latest->format('Y-m-d');
        }

        return $data;
    }

    private function latestTransaction(OrderEntity $order): ?OrderTransactionEntity
    {
        $latest = null;
        foreach ($order->getTransactions() ?? [] as $transaction) {
            if ($latest === null || $transaction->getCreatedAt() > $latest->getCreatedAt()) {
                $latest = $transaction;
            }
        }

        return $latest;
    }

    /**
     * Shopware keeps three state machines per order; the most telling one
     * decides, and its own label goes with the code.
     *
     * @return array{0: string, 1: ?StateMachineStateEntity}
     */
    private function statusCode(
        ?StateMachineStateEntity $order,
        ?StateMachineStateEntity $transaction,
        ?StateMachineStateEntity $delivery,
    ): array {
        $orderName = $order?->getTechnicalName();
        $txName = $transaction?->getTechnicalName();
        $deliveryName = $delivery?->getTechnicalName();

        return match (true) {
            $orderName === 'cancelled' => ['cancelled', $order],
            $txName === 'refunded' => ['refunded', $transaction],
            $orderName === 'completed' => ['completed', $order],
            $deliveryName === 'shipped' => ['shipped', $delivery],
            $deliveryName === 'shipped_partially' => ['partially_shipped', $delivery],
            $txName === 'failed' => ['failed', $transaction],
            $orderName === 'in_progress' => ['processing', $order],
            \in_array($txName, ['paid', 'paid_partially', 'authorized'], true) => ['processing', $order],
            default => ['pending', $order],
        };
    }

    private function stateName(StateMachineStateEntity $state): string
    {
        $name = $state->getTranslation('name') ?? $state->getName();

        return \is_string($name) ? $name : '';
    }

    /**
     * @return list<array{carrier: string, number: string, url?: string}>
     */
    private function trackingEntries(OrderDeliveryEntity $delivery): array
    {
        $method = $delivery->getShippingMethod();
        $carrier = '';
        $template = '';
        if ($method !== null) {
            $name = $method->getTranslation('name') ?? $method->getName();
            $carrier = \is_string($name) ? $name : '';
            $template = (string) $method->getTranslation('trackingUrl') ?: (string) $method->getTrackingUrl();
        }

        $entries = [];
        foreach ($delivery->getTrackingCodes() as $code) {
            $number = trim((string) $code);
            if ($number === '') {
                continue;
            }
            $entry = ['carrier' => $carrier, 'number' => $number];
            // str_replace, not sprintf: a stray '%' in the template must not throw.
            if (str_contains($template, '%s')) {
                $url = str_replace('%s', rawurlencode($number), $template);
                if (preg_match('#^https://#i', $url)) {
                    $entry['url'] = $url;
                }
            }
            $entries[] = $entry;
        }

        return $entries;
    }
}
