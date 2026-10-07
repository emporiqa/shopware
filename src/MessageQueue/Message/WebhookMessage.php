<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\MessageQueue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Serialized to JSON by Messenger through its getters, so every getter is a
 * field and every field a constructor argument with a default: a message
 * queued by an older version still decodes.
 */
class WebhookMessage implements AsyncMessageInterface
{
    private readonly float $createdAt;

    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $events
     * @param int $retryCount re-sends after a rate limit (429)
     * @param int $deliveryRetryCount re-sends after Emporiqa was unreachable or answered 5xx
     * @param float|null $createdAt when the events were built (Unix time); kept on every re-send
     */
    public function __construct(
        private readonly array $events,
        private readonly int $retryCount = 0,
        private readonly int $deliveryRetryCount = 0,
        ?float $createdAt = null,
    ) {
        $this->createdAt = $createdAt ?? microtime(true);
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getDeliveryRetryCount(): int
    {
        return $this->deliveryRetryCount;
    }

    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    public function withIncrementedRetry(): self
    {
        return new self($this->events, $this->retryCount + 1, $this->deliveryRetryCount, $this->createdAt);
    }

    public function withIncrementedDeliveryRetry(): self
    {
        return new self($this->events, $this->retryCount, $this->deliveryRetryCount + 1, $this->createdAt);
    }

    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $events
     */
    public function withEvents(array $events): self
    {
        return new self($events, $this->retryCount, $this->deliveryRetryCount, $this->createdAt);
    }
}
