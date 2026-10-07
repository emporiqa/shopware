<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\MessageQueue\Message;

use Emporiqa\ShopwarePlugin\MessageQueue\Message\WebhookMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;

/**
 * Shopware queues messages as JSON through Symfony's serializer, so the
 * fields added in 1.3.2 must survive a round trip, and a message queued by
 * 1.3.1 (without them) must still decode after the update.
 */
class WebhookMessageTest extends TestCase
{
    public function testRoundTripKeepsTheRetryCountsAndTheBuildTime(): void
    {
        $serializer = Serializer::create();
        $message = (new WebhookMessage([['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']]], 0, 0, 1759833358.25))
            ->withIncrementedDeliveryRetry()
            ->withIncrementedRetry();

        $decoded = $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();

        $this->assertInstanceOf(WebhookMessage::class, $decoded);
        $this->assertSame(1, $decoded->getRetryCount());
        $this->assertSame(1, $decoded->getDeliveryRetryCount());
        $this->assertSame(1759833358.25, $decoded->getCreatedAt());
        $this->assertSame($message->getEvents(), $decoded->getEvents());
    }

    public function testAMessageQueuedByTheOlderVersionStillDecodes(): void
    {
        $serializer = Serializer::create();
        $before = microtime(true);
        $decoded = $serializer->decode([
            'body' => '{"events":[{"type":"product.updated","data":{"identification_number":"product-1"}}],"retryCount":2}',
            'headers' => ['type' => WebhookMessage::class, 'Content-Type' => 'application/json'],
        ])->getMessage();

        $this->assertInstanceOf(WebhookMessage::class, $decoded);
        $this->assertSame(2, $decoded->getRetryCount());
        $this->assertSame(0, $decoded->getDeliveryRetryCount());
        $this->assertGreaterThanOrEqual($before, $decoded->getCreatedAt());
    }

    public function testAWholeSecondBuildTimeDecodesAsAFloat(): void
    {
        $serializer = Serializer::create();
        $decoded = $serializer->decode($serializer->encode(new Envelope(new WebhookMessage([], 0, 0, 1759833358.0))))->getMessage();

        $this->assertInstanceOf(WebhookMessage::class, $decoded);
        $this->assertSame(1759833358.0, $decoded->getCreatedAt());
    }
}
