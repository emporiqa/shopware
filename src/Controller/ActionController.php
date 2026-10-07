<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Controller;

use Emporiqa\ShopwarePlugin\Service\ActionGuard;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\ConnectServiceInterface;
use Emporiqa\ShopwarePlugin\Service\CustomerInfoService;
use Emporiqa\ShopwarePlugin\Service\CustomerPriceService;
use Emporiqa\ShopwarePlugin\Service\OrderStatusService;
use Emporiqa\ShopwarePlugin\Service\SignatureHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints of Emporiqa's ready-made rules. The base sent at connect is
 * <storefront domain>/emporiqa/; Emporiqa appends actions/<rule>:
 *
 *   actions/order-status     read-only order lookup, signed both ways (scheme 2)
 *   actions/customer-prices  what one signed-in customer pays, signed the same way
 *   actions/customer-info    who the signed-in customer is and their newest orders,
 *                            signed the same way
 *   actions/verify           the origin proof during one-click connect, and the
 *                            signed endpoint challenge
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class ActionController extends StorefrontController
{
    public const ROUTES = [
        'frontend.emporiqa.actions.order_status',
        'frontend.emporiqa.actions.customer_prices',
        'frontend.emporiqa.actions.customer_info',
        'frontend.emporiqa.actions.verify',
    ];

    private const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly ConnectServiceInterface $connectService,
        private readonly OrderStatusService $orderStatus,
        private readonly CustomerPriceService $customerPrices,
        private readonly ActionGuard $guard,
        private readonly LoggerInterface $logger,
        private readonly CustomerInfoService $customerInfo,
    ) {
    }

    #[Route(path: '/emporiqa/actions/verify', name: 'frontend.emporiqa.actions.verify', methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false, '_httpCache' => false])]
    public function verify(Request $request, SalesChannelContext $context): Response
    {
        try {
            $body = $request->getContent();
            $payload = json_decode($body, true);

            if (\is_array($payload) && isset($payload['state'], $payload['nonce'])) {
                return $this->originProof($payload);
            }

            [$secret, $storeId, $refusal] = $this->authenticate($request, $body, $context);
            if ($refusal !== null) {
                return $refusal;
            }
            $requestId = $this->requestId($payload);
            if ($requestId === null || ($payload['rule'] ?? null) !== 'verify') {
                return $this->respond(400, ['status' => 'error', 'message_code' => 'invalid_field'], $secret, $storeId, '');
            }

            $challenge = \is_string($payload['challenge'] ?? null) ? $payload['challenge'] : '';
            if (!preg_match('/^[0-9a-f]{64}$/D', $challenge)) {
                return $this->respond(200, ['status' => 'rejected', 'message_code' => 'invalid_field'], $secret, $storeId, $requestId);
            }
            $key = SignatureHelper::deriveKey($secret, SignatureHelper::LABEL_RESPONSE, $storeId);

            return $this->respond(200, [
                'status' => 'found',
                'data' => ['challenge_mac' => hash_hmac('sha256', $challenge, $key)],
            ], $secret, $storeId, $requestId);
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] verify failed: ' . self::describe($e));

            return $this->respond(500, ['status' => 'error', 'message_code' => 'internal']);
        }
    }

    #[Route(path: '/emporiqa/actions/order-status', name: 'frontend.emporiqa.actions.order_status', methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false, '_httpCache' => false])]
    public function orderStatus(Request $request, SalesChannelContext $context): Response
    {
        return $this->signedAction(
            $request,
            $context,
            'order_status',
            fn (array $payload, string $storeId) => $this->guard->rateLimitHit(
                $storeId,
                OrderStatusService::field($payload, 'order_number'),
                OrderStatusService::field($payload, 'email'),
            ),
            fn (array $payload, string $storeId) => $this->orderStatus->handle($payload, $context->getContext(), $storeId),
        );
    }

    #[Route(path: '/emporiqa/actions/customer-prices', name: 'frontend.emporiqa.actions.customer_prices', methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false, '_httpCache' => false])]
    public function customerPrices(Request $request, SalesChannelContext $context): Response
    {
        return $this->signedAction(
            $request,
            $context,
            'customer_prices',
            function (array $payload, string $storeId): ?array {
                $customerId = CustomerPriceService::customerId($payload);

                return $customerId === null ? null : $this->guard->customerPriceLimitHit($storeId, $customerId);
            },
            function (array $payload, string $storeId) use ($context): ?array {
                $customerId = CustomerPriceService::customerId($payload);
                $productIds = CustomerPriceService::productIds($payload);
                if ($customerId === null || $productIds === null) {
                    return null;
                }

                return $this->customerPrices->handle($payload, $customerId, $productIds, $context, $storeId);
            },
        );
    }

    #[Route(path: '/emporiqa/actions/customer-info', name: 'frontend.emporiqa.actions.customer_info', methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false, '_httpCache' => false])]
    public function customerInfo(Request $request, SalesChannelContext $context): Response
    {
        return $this->signedAction(
            $request,
            $context,
            'customer_info',
            function (array $payload, string $storeId): ?array {
                // No customer is answered "rejected" without a lookup, so it is not counted.
                $customerId = CustomerPriceService::customerId($payload);

                return $customerId === null ? null : $this->guard->customerInfoLimitHit($storeId, $customerId);
            },
            fn (array $payload, string $storeId) => $this->customerInfo->handle($payload, $context->getContext(), $storeId),
        );
    }

    /**
     * One signed, read-only action: signature, request_id replay (the same
     * answer for 10 minutes), rate limit, then the handler. A handler
     * answering null means the request was malformed (400).
     *
     * @param callable(array<string, mixed>, string): ?array{scope: string, retry_after: int} $limit
     * @param callable(array<string, mixed>, string): ?array<string, mixed> $handle
     */
    private function signedAction(Request $request, SalesChannelContext $context, string $rule, callable $limit, callable $handle): Response
    {
        $secret = '';
        $storeId = '';
        $requestId = null;
        try {
            $body = $request->getContent();
            [$secret, $storeId, $refusal] = $this->authenticate($request, $body, $context);
            if ($refusal !== null) {
                return $refusal;
            }

            $payload = json_decode($body, true);
            $requestId = $this->requestId($payload);
            if ($requestId === null || !\is_array($payload) || ($payload['rule'] ?? null) !== $rule) {
                return $this->respond(400, ['status' => 'error', 'message_code' => 'invalid_field'], $secret, $storeId, '');
            }

            $remembered = $this->guard->remembered($storeId, $requestId);
            if ($remembered !== null) {
                return $this->respond($remembered[0], $remembered[1], $secret, $storeId, $requestId);
            }

            // Counted after the dedupe, so Emporiqa's retry of one call is free.
            $limited = $limit($payload, $storeId);
            if ($limited !== null) {
                return $this->respond(
                    429,
                    ['status' => 'error', 'message_code' => 'rate_limited', 'data' => ['scope' => $limited['scope']]],
                    $secret,
                    $storeId,
                    $requestId,
                    ['Retry-After' => (string) $limited['retry_after']],
                );
            }

            $envelope = $handle($payload, $storeId);
            if ($envelope === null) {
                return $this->respond(400, ['status' => 'error', 'message_code' => 'invalid_field'], $secret, $storeId, $requestId);
            }
            $encoded = (string) json_encode($envelope, self::JSON_FLAGS);
            $this->guard->remember($storeId, $requestId, 200, $encoded);

            return $this->respond(200, $encoded, $secret, $storeId, $requestId);
        } catch (\Throwable $e) {
            $this->logger->error('[Emporiqa] ' . $rule . ' failed: ' . self::describe($e));

            return $this->respond(500, ['status' => 'error', 'message_code' => 'internal'], $secret, $storeId, $requestId);
        }
    }

    /**
     * An exception for the log without its message: a database error's
     * message can quote the shopper's email or order number.
     */
    public static function describe(\Throwable $e): string
    {
        return $e::class . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    /**
     * Connect origin proof: answer only for an exchange this shop started
     * with that state, with HMAC-SHA256(code_verifier, nonce). Unsigned, since
     * a first connect has no secret yet; the verifier is the proof.
     *
     * Emporiqa's nonce is always 64 lowercase hex; anything else is refused,
     * so whoever saw `state` in the admin callback URL cannot have the shop
     * MAC a value of their choosing.
     *
     * @param array<string, mixed> $payload
     */
    private function originProof(array $payload): Response
    {
        $state = \is_string($payload['state']) ? $payload['state'] : '';
        $nonce = \is_string($payload['nonce']) ? $payload['nonce'] : '';
        $verifier = preg_match('/^[0-9a-f]{64}$/D', $nonce) ? $this->connectService->exchangingVerifier($state) : null;
        if ($verifier === null) {
            return $this->respond(404, ['status' => 'not_found']);
        }

        return $this->respond(200, ['status' => 'found', 'data' => ['nonce_mac' => hash_hmac('sha256', $nonce, $verifier)]]);
    }

    /**
     * The secret and store id, or the unsigned refusal to send. A refusal is
     * never signed: nothing proved the caller.
     *
     * @return array{0: string, 1: string, 2: ?Response}
     */
    private function authenticate(Request $request, string $body, SalesChannelContext $context): array
    {
        $salesChannelId = $context->getSalesChannelId();
        $secret = $this->config->getWebhookSecret($salesChannelId);
        $storeId = $this->config->getStoreId($salesChannelId);
        if ($secret === '' || $storeId === '') {
            return [$secret, $storeId, $this->respond(503, ['status' => 'error', 'message_code' => 'disabled'])];
        }

        $verdict = SignatureHelper::verifyHeader(
            (string) $request->headers->get('X-Emporiqa-Action-Signature', ''),
            $body,
            $secret,
            $storeId,
            SignatureHelper::LABEL_OUTBOUND,
        );
        if ($verdict !== 'ok') {
            return [$secret, $storeId, $this->respond(401, ['status' => 'error', 'message_code' => $verdict])];
        }

        return [$secret, $storeId, null];
    }

    private function requestId(mixed $payload): ?string
    {
        $requestId = \is_array($payload) ? ($payload['request_id'] ?? null) : null;

        return \is_string($requestId) && $requestId !== '' && \strlen($requestId) <= 100 ? $requestId : null;
    }

    /**
     * @param array<string, mixed>|string $envelope an array is encoded; a string is an encoded body (a remembered answer)
     * @param string|null $requestId when set, the answer carries X-Emporiqa-Response-Signature
     * @param array<string, string> $headers
     */
    private function respond(
        int $status,
        array|string $envelope,
        string $secret = '',
        string $storeId = '',
        ?string $requestId = null,
        array $headers = [],
    ): Response {
        $body = \is_array($envelope) ? (string) json_encode($envelope, self::JSON_FLAGS) : $envelope;
        $headers['Content-Type'] = 'application/json';
        $headers['Cache-Control'] = 'no-store';
        if ($requestId !== null && $secret !== '' && $storeId !== '') {
            $key = SignatureHelper::deriveKey($secret, SignatureHelper::LABEL_RESPONSE, $storeId);
            $headers['X-Emporiqa-Response-Signature'] = SignatureHelper::buildHeader($key, $requestId . '.' . $body);
        }

        return new Response($body, $status, $headers);
    }
}
