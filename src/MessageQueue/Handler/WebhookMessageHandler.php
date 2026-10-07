<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\MessageQueue\Handler;

use Emporiqa\ShopwarePlugin\Exception\RateLimitException;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use Emporiqa\ShopwarePlugin\Service\DeliveryLedger;
use Emporiqa\ShopwarePlugin\Service\TransientFailureAwareInterface;
use Emporiqa\ShopwarePlugin\Service\WebhookClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
class WebhookMessageHandler
{
    private const RATE_LIMIT_DELAY_MS = 65_000; // 65 seconds, past the 60s rate limit window
    private const MAX_RATE_LIMIT_RETRIES = 5;

    /**
     * Seconds before each re-send while Emporiqa is unreachable or answers
     * 5xx: about 2 h 21 min in all, so a short outage or a deploy loses
     * nothing. Messenger's own retry (1, 2, 4 s) gave up after 7 s and parked
     * the change in the `failed` transport, which a usual worker never reads.
     */
    public const DELIVERY_RETRY_DELAYS = [60, 300, 900, 3600, 3600, 3600];

    public function __construct(
        private readonly WebhookClientInterface $webhookClient,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly ?DeliveryLedger $ledger = null,
    ) {
    }

    public function __invoke(WebhookMessage $message): void
    {
        $events = $message->getEvents();

        if ($this->ledger !== null && ($message->getRetryCount() > 0 || $message->getDeliveryRetryCount() > 0)) {
            $events = $this->ledger->withoutSuperseded($events, $message->getCreatedAt());
            $dropped = \count($message->getEvents()) - \count($events);
            if ($dropped > 0) {
                $this->logger->info(sprintf('[Emporiqa] Not re-sending %d events: a newer version reached Emporiqa since.', $dropped));
                $message = $message->withEvents($events);
            }
        }

        if (empty($events)) {
            return;
        }

        try {
            $success = $this->webhookClient->sendBatchEvents($events);
        } catch (RateLimitException $e) {
            if ($message->getRetryCount() >= self::MAX_RATE_LIMIT_RETRIES) {
                $this->logger->error(sprintf(
                    '[Emporiqa] Rate limit retry exhausted after %d attempts, dropping %d events.',
                    self::MAX_RATE_LIMIT_RETRIES,
                    \count($events),
                ));

                throw new \RuntimeException(sprintf(
                    '[Emporiqa] Webhook rate limit exhausted after %d retries for %d events.',
                    self::MAX_RATE_LIMIT_RETRIES,
                    \count($events),
                ));
            }

            $this->logger->info(sprintf(
                '[Emporiqa] Rate limited, re-queuing %d events with %ds delay (attempt %d/%d).',
                \count($events),
                self::RATE_LIMIT_DELAY_MS / 1000,
                $message->getRetryCount() + 1,
                self::MAX_RATE_LIMIT_RETRIES,
            ));
            $this->ledger?->markRetryPending();
            $this->messageBus->dispatch($message->withIncrementedRetry(), [new DelayStamp(self::RATE_LIMIT_DELAY_MS)]);

            return;
        }

        if ($success) {
            $this->ledger?->recordDelivered($events, $message->getCreatedAt());

            return;
        }

        $detail = (string) $this->webhookClient->getLastError();
        $transient = !$this->webhookClient instanceof TransientFailureAwareInterface
            || $this->webhookClient->isLastFailureTransient();

        if (!$transient) {
            // A wrong secret or a refused payload fails the same way on every
            // re-send; it is parked in the failure transport for a retry by hand.
            $this->logger->error(sprintf(
                '[Emporiqa] Emporiqa refused %d webhook events, not retrying.%s',
                \count($events),
                $detail !== '' ? ' ' . $detail : '',
            ));

            throw new UnrecoverableMessageHandlingException(sprintf('[Emporiqa] Webhook delivery refused for %d events.', \count($events)));
        }

        $attempt = $message->getDeliveryRetryCount();
        if ($attempt >= \count(self::DELIVERY_RETRY_DELAYS)) {
            $this->logger->error(sprintf(
                '[Emporiqa] Emporiqa still unreachable after %d re-sends, giving up on %d events. Run a sync from the Emporiqa page once it is back.',
                $attempt,
                \count($events),
            ));

            throw new UnrecoverableMessageHandlingException(sprintf('[Emporiqa] Webhook delivery failed for %d events.', \count($events)));
        }

        $delay = self::DELIVERY_RETRY_DELAYS[$attempt];
        $this->logger->warning(sprintf(
            '[Emporiqa] Emporiqa unreachable, re-sending %d events in %d s (attempt %d/%d).%s',
            \count($events),
            $delay,
            $attempt + 1,
            \count(self::DELIVERY_RETRY_DELAYS),
            $detail !== '' ? ' ' . $detail : '',
        ), ['event_count' => \count($events)]);
        $this->ledger?->markRetryPending();
        $this->messageBus->dispatch($message->withIncrementedDeliveryRetry(), [new DelayStamp($delay * 1000)]);
    }
}
