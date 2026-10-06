<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Emporiqa\ShopwarePlugin\Controller\ActionController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Emporiqa's signed server-to-server calls (order status, verify, legacy
 * order tracking) must answer while the shop is in maintenance mode. The
 * storefront redirects every non-XHR request to the maintenance page
 * (StorefrontSubscriber::maintenanceResolver, priority 0), so these routes
 * are marked as XHR at priority 16: after the router (32) has set the
 * route, before that check.
 * Their own signature check decides who gets an answer.
 */
class MaintenanceBypassSubscriber implements EventSubscriberInterface
{
    private const ROUTES = [...ActionController::ROUTES, 'frontend.emporiqa.order.tracking'];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 16],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (\in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }
    }
}
