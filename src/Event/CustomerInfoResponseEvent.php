<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Event;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before Emporiqa's `customer_info` call is answered, after `data`
 * is filled, so a listener can change or remove any key or add its own:
 *
 *   customer  {name, first_name, last_name, email}, empty ones left out
 *   orders    up to 10 placed orders, newest first:
 *             [{order_number, placed_at, status_code, status_label, total, currency}]
 *
 * Put custom fields under `extra`; Emporiqa drops any other unknown key.
 * `extra` takes the same values as in OrderStatusResponseEvent:
 *
 *     public function onCustomerInfo(CustomerInfoResponseEvent $event): void
 *     {
 *         $data = $event->getData();
 *         unset($data['customer']['email']);
 *         $data['extra'] = ['loyalty_tier' => 'Gold'];
 *         $event->setData($data);
 *     }
 *
 * The answer is about the signed-in shopper only, so add nothing about
 * anyone else.
 */
class CustomerInfoResponseEvent extends Event
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly CustomerEntity $customer,
        private readonly OrderCollection $orders,
        private array $data,
    ) {
    }

    public function getCustomer(): CustomerEntity
    {
        return $this->customer;
    }

    /**
     * The orders in `data.orders`, as loaded (with their states and currency).
     */
    public function getOrders(): OrderCollection
    {
        return $this->orders;
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
