<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Emporiqa\ShopwarePlugin\Event\PostOrderFormatEvent;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

class OrderSubscriber implements EventSubscriberInterface, ResetInterface
{
    /** @var array<string, true> Order IDs already processed in this request */
    private array $processedOrderIds = [];

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     */
    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
            'state_machine.order.state_changed' => 'onOrderStateChanged',
            'state_machine.order_transaction.state_changed' => 'onTransactionStateChanged',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $orderId = $event->getOrderId();

        if (isset($this->processedOrderIds[$orderId])) {
            return;
        }
        $this->processedOrderIds[$orderId] = true;

        // Capture the emporiqa_sid cookie NOW (still in request context)
        // and persist it onto the order's customFields. State-machine
        // events fire later in admin / cron / payment-callback contexts
        // where RequestStack has no current request → cookie is gone.
        // Reading from the persisted customFields entry in those
        // handlers keeps attribution intact across the order lifecycle.
        $cookieSessionId = $this->readCookieSessionId();
        if ($cookieSessionId !== '') {
            $this->persistSessionIdOnOrder($orderId, $cookieSessionId, $event->getContext());
        }

        $fullOrder = $this->fetchOrder($orderId, $event->getContext());
        if ($fullOrder === null) {
            return;
        }

        $this->dispatchOrderWebhook($fullOrder, 'order.completed', CheckoutOrderPlacedEvent::EVENT_NAME, $event->getContext());
    }

    public function onOrderStateChanged(StateMachineStateChangeEvent $event): void
    {
        $this->handleStateChange($event, 'order.state.' . $event->getNextState()->getTechnicalName());
    }

    public function onTransactionStateChanged(StateMachineStateChangeEvent $event): void
    {
        $this->handleStateChange($event, 'order_transaction.state.' . $event->getNextState()->getTechnicalName());
    }

    private function handleStateChange(StateMachineStateChangeEvent $event, string $stateKey): void
    {
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        if (!$this->config->isConfigured()) {
            return;
        }

        $configuredStates = $this->config->getOrderCompletedStates();
        if (!\in_array($stateKey, $configuredStates, true)) {
            return;
        }

        $entityId = $event->getTransition()->getEntityId();

        $orderId = $entityId;
        if (str_starts_with($stateKey, 'order_transaction.')) {
            $orderId = $this->resolveOrderIdFromTransaction($event);
            if ($orderId === null) {
                return;
            }
        }

        if (isset($this->processedOrderIds[$orderId])) {
            return;
        }
        $this->processedOrderIds[$orderId] = true;

        $fullOrder = $this->fetchOrder($orderId, $event->getContext());
        if ($fullOrder === null) {
            return;
        }

        $this->dispatchOrderWebhook($fullOrder, 'order.completed', $stateKey, $event->getContext());
    }

    private function fetchOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('currency');

        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    private function dispatchOrderWebhook(OrderEntity $order, string $webhookType, string $stateKey, Context $context): void
    {
        $orderId = $order->getId();

        // Durable cross-request dedup (PrestaShop parity): an order already
        // marked as tracked was sent by an earlier request (placement or a
        // previous state change), the in-memory guard cannot see that.
        if ($this->isOrderTracked($order)) {
            return;
        }
        // Source-of-truth precedence: the persisted customFields entry
        // (written at place-time, see onOrderPlaced) wins; the live
        // RequestStack cookie is the fallback for first-firing on
        // place. State-machine handlers running outside a request
        // context naturally rely on the persisted value.
        $emporiqaSid = $this->getSessionIdFromCustomFields($order);
        if ($emporiqaSid === '') {
            $emporiqaSid = $this->readCookieSessionId();
        }

        $eventData = [
            'order_id' => $order->getOrderNumber() ?? $orderId,
            // Stringify monetary fields with 4-decimal precision to
            // preserve currency exactness (BHD/KWD use 3 decimals).
            // Casting through float introduces IEEE-754 drift.
            'total' => number_format((float) $order->getAmountTotal(), 4, '.', ''),
            'currency' => $order->getCurrency() !== null ? $order->getCurrency()->getIsoCode() : 'EUR',
            'emporiqa_session_id' => $emporiqaSid,
            'items' => $this->buildOrderItems($order),
        ];

        $postFormatEvent = new PostOrderFormatEvent($order, $eventData, $stateKey);
        $this->eventDispatcher->dispatch($postFormatEvent);
        $eventData = $postFormatEvent->getEventData();

        try {
            $this->messageBus->dispatch(new WebhookMessage([
                ['type' => $webhookType, 'data' => $eventData],
            ]));
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] Failed to queue order webhook.', [
                'orderId' => $orderId,
                'webhookType' => $webhookType,
                'error' => $e->getMessage(),
            ]);

            // Not persisted as tracked, a later state change may retry.
            return;
        }

        $this->persistOrderCustomFields($orderId, ['emporiqa_order_tracked' => date('c')], $context);
    }

    /**
     * @return list<array{product_id: string, variation_id: string|null, quantity: int, price: float}>
     */
    private function buildOrderItems(OrderEntity $order): array
    {
        $items = [];
        $lineItems = $order->getLineItems();
        if ($lineItems === null) {
            return $items;
        }

        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $referencedId = $lineItem->getReferencedId() ?? $lineItem->getIdentifier();
            $payload = $lineItem->getPayload();
            $price = $lineItem->getPrice();
            $unitPrice = $price !== null ? $price->getUnitPrice() : 0.0;

            $productId = $referencedId;
            $variationId = null;
            if (isset($payload['parentId'])) {
                $productId = $payload['parentId'];
                $variationId = 'variation-' . $referencedId;
            }

            $items[] = [
                'product_id' => 'product-' . $productId,
                'variation_id' => $variationId,
                'quantity' => $lineItem->getQuantity(),
                'price' => round($unitPrice, 2),
            ];
        }

        return $items;
    }

    private function resolveOrderIdFromTransaction(StateMachineStateChangeEvent $event): ?string
    {
        $transactionId = $event->getTransition()->getEntityId();

        $criteria = new Criteria([$transactionId]);
        $transaction = $this->orderTransactionRepository->search($criteria, $event->getContext())->getEntities()->first();

        if (!$transaction instanceof OrderTransactionEntity) {
            return null;
        }

        return $transaction->getOrderId();
    }

    private function readCookieSessionId(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return '';
        }

        return $this->sanitizeSessionId($request->cookies->get('emporiqa_sid', ''));
    }

    private function getSessionIdFromCustomFields(OrderEntity $order): string
    {
        $customFields = $order->getCustomFields() ?? [];

        return $this->sanitizeSessionId($customFields['emporiqa_session_id'] ?? '');
    }

    private function sanitizeSessionId(mixed $value): string
    {
        if (!\is_string($value) || $value === '') {
            return '';
        }

        return preg_match('/^[a-zA-Z0-9_\-]{1,128}$/', $value) === 1 ? $value : '';
    }

    private function persistSessionIdOnOrder(string $orderId, string $sessionId, Context $context): void
    {
        // Persistence is best-effort. If it fails, attribution for this
        // specific order may degrade on later state-change events but the
        // place-time webhook still fires with the cookie value.
        $this->persistOrderCustomFields($orderId, ['emporiqa_session_id' => $sessionId], $context);
    }

    private function isOrderTracked(OrderEntity $order): bool
    {
        $value = ($order->getCustomFields() ?? [])['emporiqa_order_tracked'] ?? null;

        return \is_string($value) && $value !== '';
    }

    /**
     * @param array<string, string> $customFields
     */
    private function persistOrderCustomFields(string $orderId, array $customFields, Context $context): void
    {
        try {
            // Merge into existing customFields rather than overwriting —
            // other plugins also use this column. Shopware accepts an
            // arbitrary key without us registering a CustomFieldSet
            // (the admin UI just won't expose it, which is fine, these
            // values are internal plumbing).
            $this->orderRepository->update(
                [
                    [
                        'id' => $orderId,
                        'customFields' => $customFields,
                    ],
                ],
                $context,
            );
        } catch (\Throwable $e) {
            // Best-effort: a failed write must never break checkout or
            // state-machine transitions.
            $this->logger->warning('[Emporiqa] Failed to persist custom fields on order.', [
                'orderId' => $orderId,
                'customFieldKeys' => array_keys($customFields),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function reset(): void
    {
        $this->processedOrderIds = [];
    }
}
