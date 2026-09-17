<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Subscriber\OrderSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use Emporiqa\ShopwarePlugin\Tests\Support\EntityCollectionHelper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrderSubscriberTest extends TestCase
{
    use EntityCollectionHelper;

    private ConfigServiceInterface&MockObject $config;
    private MessageBusInterface&MockObject $messageBus;
    private EntityRepository&MockObject $orderRepository;
    private EntityRepository&MockObject $orderTransactionRepository;
    private RequestStack&MockObject $requestStack;
    private LoggerInterface&MockObject $logger;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private OrderSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->orderTransactionRepository = $this->createMock(EntityRepository::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->subscriber = new OrderSubscriber(
            $this->config,
            $this->messageBus,
            $this->orderRepository,
            $this->orderTransactionRepository,
            $this->requestStack,
            $this->logger,
            $this->eventDispatcher,
        );
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = OrderSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(CheckoutOrderPlacedEvent::class, $events);
        $this->assertArrayHasKey('state_machine.order.state_changed', $events);
        $this->assertArrayHasKey('state_machine.order_transaction.state_changed', $events);
        $this->assertSame('onOrderPlaced', $events[CheckoutOrderPlacedEvent::class]);
        $this->assertSame('onOrderStateChanged', $events['state_machine.order.state_changed']);
        $this->assertSame('onTransactionStateChanged', $events['state_machine.order_transaction.state_changed']);
    }

    // --- onOrderPlaced tests ---

    public function testOnOrderPlacedSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);

        $event = $this->createOrderPlacedEvent('order-placed-1', '20001');

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onOrderPlaced($event);
    }

    public function testOnOrderPlacedDispatchesOrderPlacedWebhook(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-placed-2');
        $fullOrder->method('getOrderNumber')->willReturn('20002');
        $fullOrder->method('getAmountTotal')->willReturn(149.99);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $event = $this->createOrderPlacedEvent('order-placed-2', '20002');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                if (!$message instanceof WebhookMessage) {
                    return false;
                }
                $events = $message->getEvents();

                return \count($events) === 1
                    && $events[0]['type'] === 'order.completed'
                    && $events[0]['data']['order_id'] === '20002'
                    && $events[0]['data']['total'] === '149.9900'
                    && $events[0]['data']['currency'] === 'EUR';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderPlaced($event);
    }

    public function testOnOrderPlacedSkipsWhenOrderNotFound(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn(null);
        $searchResult->method('getEntities')->willReturn(self::entityCollection(null));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $event = $this->createOrderPlacedEvent('order-missing', '20003');

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onOrderPlaced($event);
    }

    public function testOnOrderPlacedSkipsDuplicateOrderId(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-dup-placed');
        $fullOrder->method('getOrderNumber')->willReturn('20004');
        $fullOrder->method('getAmountTotal')->willReturn(30.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $event = $this->createOrderPlacedEvent('order-dup-placed', '20004');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderPlaced($event);
        $this->subscriber->onOrderPlaced($event);
    }

    public function testOnOrderPlacedIncludesEmporiqaSessionId(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $request = new Request([], [], [], ['emporiqa_sid' => 'placed-session-abc']);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-session-placed');
        $fullOrder->method('getOrderNumber')->willReturn('20005');
        $fullOrder->method('getAmountTotal')->willReturn(55.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $event = $this->createOrderPlacedEvent('order-session-placed', '20005');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                $events = $message->getEvents();

                return $events[0]['data']['emporiqa_session_id'] === 'placed-session-abc';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderPlaced($event);
    }

    public function testOnOrderPlacedDispatchesOrderCompletedType(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-placed-only');
        $fullOrder->method('getOrderNumber')->willReturn('20006');
        $fullOrder->method('getAmountTotal')->willReturn(20.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $event = $this->createOrderPlacedEvent('order-placed-only', '20006');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                $events = $message->getEvents();

                return $events[0]['type'] === 'order.completed';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderPlaced($event);
    }

    // --- onOrderStateChanged tests ---

    public function testOnOrderStateChangedSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);

        $event = $this->createStateChangeEvent('order-123', 'completed');

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testOnOrderStateChangedSkipsNonConfiguredState(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getOrderCompletedStates')->willReturn(['order.state.completed']);

        $event = $this->createStateChangeEvent('order-123', 'cancelled');

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testOnOrderStateChangedDispatchesForConfiguredState(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getOrderCompletedStates')->willReturn(['order.state.completed', 'order_transaction.state.paid']);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-abc-123');
        $fullOrder->method('getOrderNumber')->willReturn('10001');
        $fullOrder->method('getAmountTotal')->willReturn(99.99);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $event = $this->createStateChangeEvent('order-abc-123', 'completed');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                if (!$message instanceof WebhookMessage) {
                    return false;
                }
                $events = $message->getEvents();

                return \count($events) === 1
                    && $events[0]['type'] === 'order.completed'
                    && $events[0]['data']['order_id'] === '10001'
                    && $events[0]['data']['total'] === '99.9900'
                    && $events[0]['data']['currency'] === 'EUR';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testOnOrderStateChangedSkipsDuplicateOrderId(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getOrderCompletedStates')->willReturn(['order.state.completed']);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-dup-001');
        $fullOrder->method('getOrderNumber')->willReturn('10002');
        $fullOrder->method('getAmountTotal')->willReturn(50.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $event = $this->createStateChangeEvent('order-dup-001', 'completed');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderStateChanged($event);
        $this->subscriber->onOrderStateChanged($event);
    }

    public function testOnOrderStateChangedSkipsWhenOrderNotFound(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getOrderCompletedStates')->willReturn(['order.state.completed']);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn(null);
        $searchResult->method('getEntities')->willReturn(self::entityCollection(null));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $event = $this->createStateChangeEvent('order-missing', 'completed');

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testOnOrderStateChangedReadsEmporiqaSessionIdFromCookie(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getOrderCompletedStates')->willReturn(['order.state.completed']);

        $request = new Request([], [], [], ['emporiqa_sid' => 'session-cookie-value']);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-cookie-001');
        $fullOrder->method('getOrderNumber')->willReturn('10003');
        $fullOrder->method('getAmountTotal')->willReturn(25.50);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $event = $this->createStateChangeEvent('order-cookie-001', 'completed');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                $events = $message->getEvents();

                return $events[0]['data']['emporiqa_session_id'] === 'session-cookie-value';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderStateChanged($event);
    }

    // --- strict emporiqa session id validation (I1) ---

    public function testOnOrderPlacedRejectsSessionIdContainingDot(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $request = new Request([], [], [], ['emporiqa_sid' => 'session.with.dots']);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-sid-dot');
        $fullOrder->method('getOrderNumber')->willReturn('50001');
        $fullOrder->method('getAmountTotal')->willReturn(15.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $event = $this->createOrderPlacedEvent('order-sid-dot', '50001');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message->getEvents()[0]['data']['emporiqa_session_id'] === '';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderPlaced($event);
    }

    public function testOnOrderPlacedRejectsSessionIdLongerThan128Chars(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $tooLong = str_repeat('a', 129);
        $request = new Request([], [], [], ['emporiqa_sid' => $tooLong]);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-sid-long');
        $fullOrder->method('getOrderNumber')->willReturn('50002');
        $fullOrder->method('getAmountTotal')->willReturn(15.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $event = $this->createOrderPlacedEvent('order-sid-long', '50002');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message->getEvents()[0]['data']['emporiqa_session_id'] === '';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderPlaced($event);
    }

    public function testOnOrderPlacedAcceptsSessionIdAt128CharsExactly(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $exactly128 = str_repeat('a', 128);
        $request = new Request([], [], [], ['emporiqa_sid' => $exactly128]);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-sid-exact');
        $fullOrder->method('getOrderNumber')->willReturn('50003');
        $fullOrder->method('getAmountTotal')->willReturn(15.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $event = $this->createOrderPlacedEvent('order-sid-exact', '50003');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) use ($exactly128) {
                return $message->getEvents()[0]['data']['emporiqa_session_id'] === $exactly128;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderPlaced($event);
    }

    public function testGetSessionIdFromCustomFieldsRejectsDotAndOverlongValues(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getOrderCompletedStates')->willReturn(['order.state.completed']);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-cf-invalid');
        $fullOrder->method('getOrderNumber')->willReturn('50004');
        $fullOrder->method('getAmountTotal')->willReturn(15.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);
        $fullOrder->method('getCustomFields')->willReturn([
            'emporiqa_session_id' => 'has.a.dot',
        ]);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);

        $event = $this->createStateChangeEvent('order-cf-invalid', 'completed');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message->getEvents()[0]['data']['emporiqa_session_id'] === '';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber->onOrderStateChanged($event);
    }

    // --- durable order dedup (customFields emporiqa_order_tracked) ---

    public function testOnOrderPlacedSkipsWhenOrderAlreadyTracked(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-tracked-1');
        $fullOrder->method('getOrderNumber')->willReturn('40001');
        $fullOrder->method('getAmountTotal')->willReturn(75.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);
        $fullOrder->method('getCustomFields')->willReturn([
            'emporiqa_order_tracked' => '2026-07-01T00:00:00+00:00',
        ]);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onOrderPlaced($this->createOrderPlacedEvent('order-tracked-1', '40001'));
    }

    public function testOnOrderStateChangedSkipsWhenOrderAlreadyTracked(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getOrderCompletedStates')->willReturn(['order.state.completed']);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-tracked-2');
        $fullOrder->method('getOrderNumber')->willReturn('40002');
        $fullOrder->method('getAmountTotal')->willReturn(80.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);
        $fullOrder->method('getCustomFields')->willReturn([
            'emporiqa_order_tracked' => '2026-07-01T00:00:00+00:00',
        ]);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onOrderStateChanged($this->createStateChangeEvent('order-tracked-2', 'completed'));
    }

    public function testOrderTrackedIsPersistedAfterSuccessfulDispatch(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-persist-1');
        $fullOrder->method('getOrderNumber')->willReturn('40003');
        $fullOrder->method('getAmountTotal')->willReturn(12.34);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $this->messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->orderRepository
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (array $payload) {
                $tracked = $payload[0]['customFields']['emporiqa_order_tracked'] ?? null;

                return $payload[0]['id'] === 'order-persist-1'
                    && \is_string($tracked)
                    && $tracked !== '';
            }));

        $this->subscriber->onOrderPlaced($this->createOrderPlacedEvent('order-persist-1', '40003'));
    }

    public function testOrderTrackedIsNotPersistedWhenDispatchFails(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $fullOrder = $this->createMock(OrderEntity::class);
        $fullOrder->method('getId')->willReturn('order-persist-2');
        $fullOrder->method('getOrderNumber')->willReturn('40004');
        $fullOrder->method('getAmountTotal')->willReturn(45.00);
        $fullOrder->method('getLineItems')->willReturn(null);
        $fullOrder->method('getCurrency')->willReturn(null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($fullOrder);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($fullOrder));
        $this->orderRepository->method('search')->willReturn($searchResult);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $this->messageBus->method('dispatch')->willThrowException(new \RuntimeException('bus down'));

        $this->orderRepository->expects($this->never())->method('update');

        $this->subscriber->onOrderPlaced($this->createOrderPlacedEvent('order-persist-2', '40004'));
    }

    public function testPlacedAndCompletedBothFireForSeparateOrderIds(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getOrderCompletedStates')->willReturn(['order.state.completed']);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $placedOrder = $this->createMock(OrderEntity::class);
        $placedOrder->method('getId')->willReturn('order-A');
        $placedOrder->method('getOrderNumber')->willReturn('30001');
        $placedOrder->method('getAmountTotal')->willReturn(10.00);
        $placedOrder->method('getLineItems')->willReturn(null);
        $placedOrder->method('getCurrency')->willReturn(null);

        $completedOrder = $this->createMock(OrderEntity::class);
        $completedOrder->method('getId')->willReturn('order-B');
        $completedOrder->method('getOrderNumber')->willReturn('30002');
        $completedOrder->method('getAmountTotal')->willReturn(20.00);
        $completedOrder->method('getLineItems')->willReturn(null);
        $completedOrder->method('getCurrency')->willReturn(null);

        $this->orderRepository->method('search')
            ->willReturnCallback(function ($criteria) use ($placedOrder, $completedOrder) {
                $ids = $criteria->getIds();
                $result = $this->createMock(EntitySearchResult::class);
                if (\in_array('order-A', $ids, true)) {
                    $result->method('first')->willReturn($placedOrder);
                    $result->method('getEntities')->willReturn(self::entityCollection($placedOrder));
                } else {
                    $result->method('first')->willReturn($completedOrder);
                    $result->method('getEntities')->willReturn(self::entityCollection($completedOrder));
                }

                return $result;
            });

        $this->messageBus
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $placedEvent = $this->createOrderPlacedEvent('order-A', '30001');
        $this->subscriber->onOrderPlaced($placedEvent);

        $completedEvent = $this->createStateChangeEvent('order-B', 'completed');
        $this->subscriber->onOrderStateChanged($completedEvent);
    }

    /**
     * @return CheckoutOrderPlacedEvent&MockObject
     */
    private function createOrderPlacedEvent(string $orderId, string $orderNumber): CheckoutOrderPlacedEvent&MockObject
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $event = $this->createMock(CheckoutOrderPlacedEvent::class);
        $event->method('getOrderId')->willReturn($orderId);
        $event->method('getOrder')->willReturn($order);
        $event->method('getContext')->willReturn(Context::createDefaultContext());

        return $event;
    }

    /**
     * @return StateMachineStateChangeEvent&MockObject
     */
    private function createStateChangeEvent(string $entityId, string $toStateTechnicalName): StateMachineStateChangeEvent&MockObject
    {
        $nextState = $this->createMock(StateMachineStateEntity::class);
        $nextState->method('getTechnicalName')->willReturn($toStateTechnicalName);

        $previousState = $this->createMock(StateMachineStateEntity::class);
        $previousState->method('getTechnicalName')->willReturn('open');

        $transition = $this->createMock(Transition::class);
        $transition->method('getEntityId')->willReturn($entityId);

        $context = $this->createMock(Context::class);

        $event = $this->createMock(StateMachineStateChangeEvent::class);
        $event->method('getTransition')->willReturn($transition);
        $event->method('getNextState')->willReturn($nextState);
        $event->method('getPreviousState')->willReturn($previousState);
        $event->method('getTransitionSide')->willReturn(StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER);
        $event->method('getContext')->willReturn($context);

        return $event;
    }
}
