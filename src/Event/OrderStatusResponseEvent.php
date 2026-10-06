<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Event;

use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before a found order is answered to Emporiqa's Order status
 * rule. Extensions may adjust `data` (status_code, status_label, placed_at,
 * tracking, estimated_delivery); keys outside that schema are dropped by
 * Emporiqa.
 */
class OrderStatusResponseEvent extends Event
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly OrderEntity $order,
        private array $data,
    ) {
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): void
    {
        $this->data = $data;
    }
}
