<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\MessageQueue\Handler;

use Emporiqa\ShopwarePlugin\Exception\RateLimitException;
use Emporiqa\ShopwarePlugin\MessageQueue\Handler\WebhookMessageHandler;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\WebhookClientInterface;
use Emporiqa\ShopwarePlugin\Tests\Support\InMemoryDeliveryLedger;
use Emporiqa\ShopwarePlugin\Tests\Support\TransientWebhookClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class WebhookMessageHandlerTest extends TestCase
{
    use InMemoryDeliveryLedger;

    private WebhookClientInterface&MockObject $webhookClient;
    private MessageBusInterface&MockObject $messageBus;
    private LoggerInterface&MockObject $logger;
    private WebhookMessageHandler $handler;

    protected function setUp(): void
    {
        $this->webhookClient = $this->createMock(WebhookClientInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new WebhookMessageHandler($this->webhookClient, $this->messageBus, $this->logger);
    }

    public function testInvokeSendsBatchEvents(): void
    {
        $events = [
            ['type' => 'product.created', 'data' => ['name' => 'Test Product']],
            ['type' => 'product.updated', 'data' => ['name' => 'Updated Product']],
        ];

        $message = new WebhookMessage($events);

        $this->webhookClient
            ->expects($this->once())
            ->method('sendBatchEvents')
            ->with($events)
            ->willReturn(true);

        $this->logger->expects($this->never())->method('warning');

        ($this->handler)($message);
    }

    public function testInvokeSkipsEmptyEvents(): void
    {
        $message = new WebhookMessage([]);

        $this->webhookClient->expects($this->never())->method('sendBatchEvents');
        $this->logger->expects($this->never())->method('warning');

        ($this->handler)($message);
    }

    public function testInvokeRequeuesWithDelayOnRateLimit(): void
    {
        $events = [
            ['type' => 'product.updated', 'data' => ['name' => 'Rate Limited Product']],
        ];

        $message = new WebhookMessage($events);

        $this->webhookClient
            ->expects($this->once())
            ->method('sendBatchEvents')
            ->willThrowException(new RateLimitException('Rate limit exceeded (HTTP 429).'));

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(function (WebhookMessage $requeued) use ($events) {
                    return $requeued->getEvents() === $events && $requeued->getRetryCount() === 1;
                }),
                $this->callback(function (array $stamps) {
                    foreach ($stamps as $stamp) {
                        if ($stamp instanceof DelayStamp && $stamp->getDelay() >= 60_000) {
                            return true;
                        }
                    }
                    return false;
                }),
            )
            ->willReturn(new Envelope($message));

        $this->logger->expects($this->never())->method('warning');

        ($this->handler)($message);
    }

    public function testInvokeDropsMessageAfterMaxRetries(): void
    {
        $events = [
            ['type' => 'product.updated', 'data' => ['name' => 'Exhausted Product']],
        ];

        $message = new WebhookMessage($events, 5);

        $this->webhookClient
            ->expects($this->once())
            ->method('sendBatchEvents')
            ->willThrowException(new RateLimitException('Rate limit exceeded (HTTP 429).'));

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Rate limit retry exhausted'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook rate limit exhausted after 5 retries');

        ($this->handler)($message);
    }

    public function testInvokeDoesNotThrowOnRateLimit(): void
    {
        $events = [['type' => 'product.updated', 'data' => []]];
        $message = new WebhookMessage($events);

        $this->webhookClient
            ->method('sendBatchEvents')
            ->willThrowException(new RateLimitException('Rate limit exceeded (HTTP 429).'));

        $this->messageBus
            ->method('dispatch')
            ->willReturn(new Envelope($message));

        // Must not throw — rate limit causes re-queue, not failure
        ($this->handler)($message);
        $this->addToAssertionCount(1);
    }

    public function testInvokeSendsMultipleEventsSuccessfully(): void
    {
        $events = [
            ['type' => 'product.created', 'data' => ['name' => 'Product 1']],
            ['type' => 'product.created', 'data' => ['name' => 'Product 2']],
            ['type' => 'product.created', 'data' => ['name' => 'Product 3']],
        ];

        $message = new WebhookMessage($events);

        $this->webhookClient
            ->expects($this->once())
            ->method('sendBatchEvents')
            ->with($events)
            ->willReturn(true);

        $this->logger->expects($this->never())->method('warning');

        ($this->handler)($message);
    }

    /**
     * S1: Emporiqa down for longer than Messenger's own retry (1, 2, 4 s)
     * must not park a live change in the `failed` transport, which a usual
     * worker never reads. The handler re-sends it itself, later each time.
     */
    public function testUnreachableEmporiqaIsRetriedWithAGrowingDelay(): void
    {
        $client = $this->createMock(TransientWebhookClient::class);
        $client->method('sendBatchEvents')->willReturn(false);
        $client->method('isLastFailureTransient')->willReturn(true);
        $events = [['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']]];
        $delays = [];
        $attempts = [];
        $this->messageBus->method('dispatch')->willReturnCallback(
            function (WebhookMessage $requeued, array $stamps) use (&$delays, &$attempts) {
                $this->assertSame(1000.5, $requeued->getCreatedAt(), 'the build time travels with every re-send');
                $attempts[] = $requeued->getDeliveryRetryCount();
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DelayStamp) {
                        $delays[] = $stamp->getDelay();
                    }
                }

                return new Envelope($requeued);
            },
        );

        $handler = new WebhookMessageHandler($client, $this->messageBus, $this->logger);
        foreach (WebhookMessageHandler::DELIVERY_RETRY_DELAYS as $i => $unused) {
            $handler(new WebhookMessage($events, 0, $i, 1000.5));
        }

        $this->assertSame([60_000, 300_000, 900_000, 3_600_000, 3_600_000, 3_600_000], $delays);
        $this->assertSame([1, 2, 3, 4, 5, 6], $attempts);
    }

    public function testUnreachableEmporiqaGivesUpAfterTheLastRetry(): void
    {
        $client = $this->createMock(TransientWebhookClient::class);
        $client->method('sendBatchEvents')->willReturn(false);
        $client->method('isLastFailureTransient')->willReturn(true);
        $this->messageBus->expects($this->never())->method('dispatch');
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('still unreachable after 6 re-sends'));

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Webhook delivery failed for 3 events');

        $events = [
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'product-1']],
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'product-2']],
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'product-3']],
        ];
        (new WebhookMessageHandler($client, $this->messageBus, $this->logger))(
            new WebhookMessage($events, 0, \count(WebhookMessageHandler::DELIVERY_RETRY_DELAYS)),
        );
    }

    /**
     * A 4xx (wrong secret, refused payload) is answered the same on every
     * re-send: never retried, parked in the failure transport at once.
     */
    public function testARefusedDeliveryIsNotRetried(): void
    {
        $client = $this->createMock(TransientWebhookClient::class);
        $client->method('sendBatchEvents')->willReturn(false);
        $client->method('isLastFailureTransient')->willReturn(false);
        $client->method('getLastError')->willReturn('Invalid signature');
        $this->messageBus->expects($this->never())->method('dispatch');
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('refused 1 webhook events, not retrying. Invalid signature'));

        $this->expectException(UnrecoverableMessageHandlingException::class);

        (new WebhookMessageHandler($client, $this->messageBus, $this->logger))(
            new WebhookMessage([['type' => 'product.updated', 'data' => []]]),
        );
    }

    public function testAClientThatCannotTellIsRetriedAsTransient(): void
    {
        // $this->webhookClient implements WebhookClientInterface only (a merchant's decorator).
        $this->webhookClient->method('sendBatchEvents')->willReturn(false);
        $this->messageBus->expects($this->once())->method('dispatch')
            ->with($this->callback(fn (WebhookMessage $m) => $m->getDeliveryRetryCount() === 1))
            ->willReturnCallback(fn (WebhookMessage $m) => new Envelope($m));

        ($this->handler)(new WebhookMessage([['type' => 'product.updated', 'data' => []]]));
    }

    /**
     * A re-send of an older version must not overwrite one that reached
     * Emporiqa since (an edit saved during the outage, saved again after).
     */
    public function testARetryDropsEventsANewerDeliverySuperseded(): void
    {
        $ledger = $this->inMemoryLedger();
        $handler = new WebhookMessageHandler($this->webhookClient, $this->messageBus, $this->logger, $ledger);
        $old = ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'name' => 'old']];
        $other = ['type' => 'product.updated', 'data' => ['identification_number' => 'product-2', 'name' => 'untouched']];
        $builtAt = microtime(true) - 10;

        $sent = [];
        $this->webhookClient->method('sendBatchEvents')->willReturnCallback(function (array $events) use (&$sent) {
            $sent[] = $events;

            return true;
        });

        $newer = ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'name' => 'new']];
        $handler(new WebhookMessage([$newer]));
        $handler(new WebhookMessage([$old, $other], 0, 2, $builtAt));

        $this->assertSame([[$newer], [$other]], $sent);
    }

    /**
     * Two versions of one product both wait for a retry; the older one comes
     * due and reaches Emporiqa first. The newer one must still be sent.
     */
    public function testAnOlderVersionDeliveredLateDoesNotDropTheNewerRetry(): void
    {
        $handler = new WebhookMessageHandler($this->webhookClient, $this->messageBus, $this->logger, $this->inMemoryLedger());
        $v1 = ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'name' => 'v1']];
        $v2 = ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'name' => 'v2']];
        $sent = [];
        $this->webhookClient->method('sendBatchEvents')->willReturnCallback(function (array $events) use (&$sent) {
            $sent[] = $events;

            return true;
        });

        $handler(new WebhookMessage([$v1], 0, 1, 1000.0));
        $handler(new WebhookMessage([$v2], 0, 1, 1010.0));
        // And had v2 arrived first, v1's retry would be the stale one.
        $handler(new WebhookMessage([$v1], 0, 2, 1000.0));

        $this->assertSame([[$v1], [$v2]], $sent);
    }

    public function testARetryWhoseEventsWereAllSupersededSendsNothing(): void
    {
        $ledger = $this->inMemoryLedger();
        $builtAt = microtime(true) - 10;
        $ledger->recordDelivered([['type' => 'product.deleted', 'data' => ['identification_number' => 'product-1']]], $builtAt + 5);

        $this->webhookClient->expects($this->never())->method('sendBatchEvents');

        (new WebhookMessageHandler($this->webhookClient, $this->messageBus, $this->logger, $ledger))(
            new WebhookMessage([['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']]], 1, 0, $builtAt),
        );
    }

    public function testAFirstSendIsNeverFiltered(): void
    {
        $ledger = $this->inMemoryLedger();
        $ledger->recordDelivered([['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']]], microtime(true));
        $event = ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']];
        $this->webhookClient->expects($this->once())->method('sendBatchEvents')->with([$event])->willReturn(true);

        (new WebhookMessageHandler($this->webhookClient, $this->messageBus, $this->logger, $ledger))(
            new WebhookMessage([$event], 0, 0, microtime(true) - 10),
        );
    }
}
