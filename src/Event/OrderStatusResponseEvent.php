<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Event;

use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before a found order is answered to Emporiqa's Order status
 * rule, after `data` is filled, so a listener can change any key or add its
 * own. The keys: status_code, status_label, placed_at, tracking,
 * estimated_delivery, order_number, customer_name, currency, items, totals,
 * payment_method, payment_status, shipping_method, delivery_time,
 * shipping_address, billing_address.
 *
 * Put custom fields under `extra`; Emporiqa drops any other unknown key.
 * `extra` takes string keys with string, number or boolean values, or
 * nested arrays, at most 3 levels deep, 30 keys in all, strings up to 500
 * characters. The chat answers with them when the shopper asks:
 *
 *     public function onOrderStatus(OrderStatusResponseEvent $event): void
 *     {
 *         $data = $event->getData();
 *         $data['extra'] = ['gift_wrap' => true, 'pickup_point' => 'Store Berlin-Mitte'];
 *         $event->setData($data);
 *     }
 *
 * Only data the shopper may see: the order is shown after they proved it is
 * theirs (order number and email, or signed in).
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
