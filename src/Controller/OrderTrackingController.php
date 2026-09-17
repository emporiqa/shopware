<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Controller;

use Emporiqa\ShopwarePlugin\Event\OrderTrackingResponseEvent;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\WebhookClient;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class OrderTrackingController extends StorefrontController
{
    private const TIMESTAMP_TOLERANCE = 300;

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly EntityRepository $orderRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[Route(path: '/emporiqa/api/order/tracking', name: 'frontend.emporiqa.order.tracking', methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false])]
    public function tracking(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->config->isOrderTrackingEnabled($context->getSalesChannelId())) {
            return new JsonResponse(['error' => 'Order tracking is disabled'], Response::HTTP_NOT_FOUND);
        }

        $signature = $request->headers->get('X-Emporiqa-Signature', '');
        $rawBody = $request->getContent();
        $secret = $this->config->getWebhookSecret($context->getSalesChannelId());

        if ($signature === '' || $secret === '') {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!WebhookClient::verifySignature($rawBody, $signature, $secret)) {
            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($rawBody, true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Validate timestamp
        $timestamp = $data['timestamp'] ?? 0;
        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            return new JsonResponse(['error' => 'Request expired'], Response::HTTP_UNAUTHORIZED);
        }

        $orderIdentifier = $data['order_identifier'] ?? '';
        if ($orderIdentifier === '') {
            return new JsonResponse(['error' => 'Missing order identifier'], Response::HTTP_BAD_REQUEST);
        }

        $order = $this->findOrder($orderIdentifier, $context->getContext());

        if (!$order instanceof OrderEntity) {
            return $this->orderNotFoundResponse();
        }

        // Email verification: required when config says so, or when provided.
        // A failed verification returns the exact same response as an order
        // not being found at all, this deliberately makes the two
        // indistinguishable so an anonymous caller cannot use verification
        // failures to probe which order numbers exist (PS parity).
        $verificationFields = $data['verification_fields'] ?? [];
        $requireEmail = $this->config->isOrderRequireEmail($context->getSalesChannelId());
        $providedEmail = $verificationFields['email'] ?? '';

        if ($requireEmail || $providedEmail !== '') {
            if ($providedEmail === '' || !$this->emailMatchesOrder($order, $providedEmail)) {
                return $this->orderNotFoundResponse();
            }
        }

        $data = $this->formatOrderTracking($order);

        $responseEvent = new OrderTrackingResponseEvent($order, $data);
        $this->eventDispatcher->dispatch($responseEvent);

        return new JsonResponse($responseEvent->getData());
    }

    /**
     * Looks up the order by number in two steps: a slim, association-free
     * query to learn its language, then a single full-association fetch
     * using a language-aware context (the order's own language chain when
     * it differs from the requesting context, else the requesting context
     * unchanged). This replaces the old pattern of an eager full fetch
     * followed by an identical full re-fetch in localizeOrder() whenever
     * the language differed, the first full result was simply discarded.
     */
    private function findOrder(string $orderIdentifier, Context $context): ?OrderEntity
    {
        $slimOrder = $this->findOrderSlim($orderIdentifier, $context);
        if ($slimOrder === null) {
            return null;
        }

        $fetchContext = $context;
        $orderLanguageId = $slimOrder->getLanguageId();
        if ($orderLanguageId !== $context->getLanguageId()) {
            $fetchContext = new Context(
                $context->getSource(),
                languageIdChain: [$orderLanguageId, Defaults::LANGUAGE_SYSTEM],
            );
        }

        return $this->findOrderFull($orderIdentifier, $fetchContext);
    }

    private function findOrderSlim(string $orderIdentifier, Context $context): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderIdentifier));
        $criteria->setLimit(1);

        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    private function findOrderFull(string $orderIdentifier, Context $context): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderIdentifier));
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('addresses.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->setLimit(1);

        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    private function emailMatchesOrder(OrderEntity $order, string $email): bool
    {
        $orderCustomer = $order->getOrderCustomer();
        if ($orderCustomer === null) {
            return false;
        }

        $orderEmail = mb_strtolower(trim($orderCustomer->getEmail()));

        return $orderEmail === mb_strtolower(trim($email));
    }

    private function orderNotFoundResponse(): JsonResponse
    {
        return new JsonResponse(['error' => 'Order not found.'], Response::HTTP_NOT_FOUND);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrderTracking(OrderEntity $order): array
    {
        $items = [];
        $lineItems = $order->getLineItems();
        if ($lineItems !== null) {
            foreach ($lineItems as $lineItem) {
                if ($lineItem->getType() !== 'product') {
                    continue;
                }

                $payload = $lineItem->getPayload();
                $price = $lineItem->getPrice();

                $items[] = [
                    'name' => $lineItem->getLabel(),
                    'sku' => $payload['productNumber'] ?? '',
                    'quantity' => $lineItem->getQuantity(),
                    'total' => $price !== null ? round($price->getTotalPrice(), 2) : 0.0,
                ];
            }
        }

        $currency = $order->getCurrency();
        $state = $order->getStateMachineState();

        $billingAddress = $this->extractBillingAddress($order);
        $shippingAddress = $this->extractShippingAddress($order);
        $tracking = $this->extractTracking($order);

        return [
            'order_number' => $order->getOrderNumber(),
            'status' => $state !== null ? ($state->getTranslation('name') ?? $state->getName()) : '',
            'date_created' => $order->getOrderDateTime()->format('c'),
            'total' => round($order->getAmountTotal(), 2),
            'currency' => $currency !== null ? $currency->getIsoCode() : 'EUR',
            'payment_method' => $this->extractPaymentMethod($order),
            'billing_address' => $billingAddress,
            'shipping_address' => $shippingAddress,
            'items' => $items,
            'carrier' => $tracking['carrier'],
            'tracking_number' => $tracking['tracking_number'],
            'tracking_url' => $tracking['tracking_url'],
        ];
    }

    /**
     * @return array{carrier: ?string, tracking_number: ?string, tracking_url: ?string}
     */
    private function extractTracking(OrderEntity $order): array
    {
        $deliveries = $order->getDeliveries();
        $delivery = $deliveries !== null ? $deliveries->first() : null;

        if ($delivery === null) {
            return ['carrier' => null, 'tracking_number' => null, 'tracking_url' => null];
        }

        $shippingMethod = $delivery->getShippingMethod();
        $carrier = $shippingMethod !== null ? ($shippingMethod->getTranslation('name') ?? $shippingMethod->getName()) : null;

        $trackingCodes = $delivery->getTrackingCodes();
        $trackingNumber = !empty($trackingCodes) ? (string) $trackingCodes[0] : null;

        $trackingUrl = null;
        $urlTemplate = $shippingMethod?->getTrackingUrl();
        if ($urlTemplate !== null && $urlTemplate !== '' && $trackingNumber !== null) {
            // A plain str_replace avoids sprintf's format-string semantics —
            // an admin-controlled template containing a stray '%' specifier
            // (e.g. a percent-encoded URL) would otherwise throw a ValueError
            // and 500 the endpoint for every order on that shipping method.
            $trackingUrl = str_contains($urlTemplate, '%s') ? str_replace('%s', $trackingNumber, $urlTemplate) : null;
        }

        return [
            'carrier' => $carrier,
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractBillingAddress(OrderEntity $order): array
    {
        $addresses = $order->getAddresses();
        if ($addresses === null || $addresses->count() === 0) {
            return $this->emptyAddress();
        }

        $billingAddressId = $order->getBillingAddressId();
        $address = $addresses->get($billingAddressId) ?? $addresses->first();

        if ($address === null) {
            return $this->emptyAddress();
        }

        $country = $address->getCountry();

        return [
            'first_name' => $address->getFirstName(),
            'last_name' => $address->getLastName(),
            'city' => $address->getCity(),
            'country' => $country !== null ? ($country->getTranslation('name') ?? $country->getName() ?? '') : '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractShippingAddress(OrderEntity $order): array
    {
        $deliveries = $order->getDeliveries();
        if ($deliveries === null || $deliveries->count() === 0) {
            return $this->extractBillingAddress($order);
        }

        $delivery = $deliveries->first();
        $address = $delivery?->getShippingOrderAddress();

        if ($address === null) {
            return $this->extractBillingAddress($order);
        }

        $country = $address->getCountry();

        return [
            'first_name' => $address->getFirstName(),
            'last_name' => $address->getLastName(),
            'city' => $address->getCity(),
            'country' => $country !== null ? ($country->getTranslation('name') ?? $country->getName() ?? '') : '',
        ];
    }

    private function extractPaymentMethod(OrderEntity $order): string
    {
        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            return '';
        }

        $transaction = $transactions->last();
        $paymentMethod = $transaction?->getPaymentMethod();
        if ($paymentMethod === null) {
            return '';
        }

        return $paymentMethod->getTranslation('name') ?? $paymentMethod->getName() ?? '';
    }

    /**
     * @return array<string, string>
     */
    private function emptyAddress(): array
    {
        return [
            'first_name' => '',
            'last_name' => '',
            'city' => '',
            'country' => '',
        ];
    }
}
