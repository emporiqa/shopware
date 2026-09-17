<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Controller;

use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class CartController extends StorefrontController
{
    private const MAX_QUANTITY = 9999;

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactory,
        private readonly RequestStack $requestStack,
        private readonly ConfigServiceInterface $config,
        private readonly EntityRepository $productRepository,
    ) {
    }

    #[Route(path: '/emporiqa/api/cart', name: 'frontend.emporiqa.cart.get', methods: ['GET'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false])]
    public function getCart(SalesChannelContext $context): JsonResponse
    {
        if (!$this->config->isCartEnabled($context->getSalesChannelId())) {
            return new JsonResponse($this->buildErrorResponse('Cart operations are disabled.'));
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        return new JsonResponse($this->buildSuccessResponse($cart, $context));
    }

    #[Route(path: '/emporiqa/api/cart/add', name: 'frontend.emporiqa.cart.add', methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false])]
    public function addToCart(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->config->isCartEnabled($context->getSalesChannelId())) {
            return new JsonResponse($this->buildErrorResponse('Cart operations are disabled.'));
        }

        $data = $this->decodeJsonBody($request);
        $productId = $this->resolveId($data['product_id'] ?? '');
        $variationId = $this->resolveId($data['variation_id'] ?? '');
        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        if ($productId === '') {
            return new JsonResponse($this->buildErrorResponse('No product ID provided.'));
        }

        if ($quantity > self::MAX_QUANTITY) {
            return new JsonResponse($this->buildErrorResponse('Quantity exceeds the allowed maximum.'));
        }

        // Use variation ID if provided, otherwise use product ID
        $targetId = $variationId !== '' ? $variationId : $productId;

        if (!Uuid::isValid($targetId)) {
            return new JsonResponse($this->buildErrorResponse('Invalid product ID.'));
        }

        // If targeting a parent product, resolve to its single active child
        // variant. When more than one active child exists, selection is
        // ambiguous and must be rejected rather than silently guessed.
        $targetId = $this->resolveToVariantIfParent($targetId, $context);

        if ($targetId === null) {
            return new JsonResponse($this->buildErrorResponse('Please select a product variation.'));
        }

        try {
            $lineItem = $this->lineItemFactory->create([
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $targetId,
                'quantity' => $quantity,
            ], $context);

            $cart = $this->cartService->getCart($context->getToken(), $context);
            $cart = $this->cartService->add($cart, $lineItem, $context);

            return new JsonResponse($this->buildSuccessResponse($cart, $context));
        } catch (\Throwable $e) {
            return new JsonResponse($this->buildErrorResponse('Failed to add item to cart.'));
        }
    }

    #[Route(path: '/emporiqa/api/cart/update', name: 'frontend.emporiqa.cart.update', methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false])]
    public function updateCart(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->config->isCartEnabled($context->getSalesChannelId())) {
            return new JsonResponse($this->buildErrorResponse('Cart operations are disabled.'));
        }

        $data = $this->decodeJsonBody($request);
        $productId = $this->resolveId($data['product_id'] ?? '');
        $variationId = $this->resolveId($data['variation_id'] ?? '');
        $quantity = (int) ($data['quantity'] ?? 1);

        if ($productId === '') {
            return new JsonResponse($this->buildErrorResponse('No product ID provided.'));
        }

        if ($quantity < 1) {
            return new JsonResponse($this->buildErrorResponse('Quantity must be greater than zero.'));
        }

        if ($quantity > self::MAX_QUANTITY) {
            return new JsonResponse($this->buildErrorResponse('Quantity exceeds the allowed maximum.'));
        }

        $targetId = $variationId !== '' ? $variationId : $productId;

        if (!Uuid::isValid($targetId)) {
            return new JsonResponse($this->buildErrorResponse('Invalid product ID.'));
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $lineItemId = $this->findLineItemId($cart, $targetId);
        if ($lineItemId === null) {
            return new JsonResponse($this->buildErrorResponse('Item not found in cart.'));
        }

        try {
            $cart = $this->cartService->changeQuantity($cart, $lineItemId, $quantity, $context);

            return new JsonResponse($this->buildSuccessResponse($cart, $context));
        } catch (\Throwable $e) {
            return new JsonResponse($this->buildErrorResponse('Failed to update cart.'));
        }
    }

    #[Route(path: '/emporiqa/api/cart/remove', name: 'frontend.emporiqa.cart.remove', methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false])]
    public function removeFromCart(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->config->isCartEnabled($context->getSalesChannelId())) {
            return new JsonResponse($this->buildErrorResponse('Cart operations are disabled.'));
        }

        $data = $this->decodeJsonBody($request);
        $productId = $this->resolveId($data['product_id'] ?? '');
        $variationId = $this->resolveId($data['variation_id'] ?? '');

        if ($productId === '') {
            return new JsonResponse($this->buildErrorResponse('No product ID provided.'));
        }

        $targetId = $variationId !== '' ? $variationId : $productId;

        if (!Uuid::isValid($targetId)) {
            return new JsonResponse($this->buildErrorResponse('Invalid product ID.'));
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $lineItemId = $this->findLineItemId($cart, $targetId);
        if ($lineItemId === null) {
            return new JsonResponse($this->buildErrorResponse('Item not found in cart.'));
        }

        try {
            $cart = $this->cartService->remove($cart, $lineItemId, $context);

            return new JsonResponse($this->buildSuccessResponse($cart, $context));
        } catch (\Throwable $e) {
            return new JsonResponse($this->buildErrorResponse('Failed to remove item.'));
        }
    }

    #[Route(path: '/emporiqa/api/cart/clear', name: 'frontend.emporiqa.cart.clear', methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false])]
    public function clearCart(SalesChannelContext $context): JsonResponse
    {
        if (!$this->config->isCartEnabled($context->getSalesChannelId())) {
            return new JsonResponse($this->buildErrorResponse('Cart operations are disabled.'));
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $lineItemIds = array_keys($cart->getLineItems()->getElements());

        try {
            foreach ($lineItemIds as $id) {
                $cart = $this->cartService->remove($cart, $id, $context);
            }

            return new JsonResponse($this->buildSuccessResponse($cart, $context));
        } catch (\Throwable $e) {
            return new JsonResponse($this->buildErrorResponse('Failed to clear cart.'));
        }
    }

    #[Route(path: '/emporiqa/api/cart/checkout-url', name: 'frontend.emporiqa.cart.checkout', methods: ['GET'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false])]
    public function getCheckoutUrl(SalesChannelContext $context): JsonResponse
    {
        if (!$this->config->isCartEnabled($context->getSalesChannelId())) {
            return new JsonResponse($this->buildErrorResponse('Cart operations are disabled.'));
        }

        $checkoutUrl = $this->generateCheckoutUrl();

        return new JsonResponse([
            'success' => true,
            'error' => null,
            'checkoutUrl' => $checkoutUrl,
            'cart' => null,
        ]);
    }

    private function generateCheckoutUrl(): string
    {
        try {
            return $this->generateUrl('frontend.checkout.confirm.page', [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            $request = $this->requestStack->getCurrentRequest();
            $base = $request ? $request->getSchemeAndHttpHost() : '';

            return $base . '/checkout/confirm';
        }
    }

    /**
     * @return array{success: bool, error: string|null, checkoutUrl: string, cart: array<string, mixed>}
     */
    private function buildSuccessResponse(Cart $cart, SalesChannelContext $context): array
    {
        $checkoutUrl = $this->generateCheckoutUrl();

        return [
            'success' => true,
            'error' => null,
            'checkoutUrl' => $checkoutUrl,
            'cart' => $this->buildCartData($cart, $context),
        ];
    }

    /**
     * @return array{success: bool, error: string, checkoutUrl: string|null, cart: array<string, mixed>|null}
     */
    private function buildErrorResponse(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'checkoutUrl' => null,
            'cart' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCartData(Cart $cart, SalesChannelContext $context): array
    {
        $items = [];
        $itemCount = 0;
        $baseUrl = $this->getBaseUrl();

        // Collect product IDs for SEO URL lookup
        $productIds = [];
        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }
            $refId = $lineItem->getReferencedId();
            if ($refId !== null) {
                $productIds[] = $refId;
            }
        }

        // Load SEO URLs for all products in cart
        $seoUrlMap = $this->loadProductSeoUrls($productIds, $context);

        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $referencedId = $lineItem->getReferencedId();
            $productId = $referencedId;
            $variationId = null;

            $payload = $lineItem->getPayload();
            if (isset($payload['parentId'])) {
                $productId = $payload['parentId'];
                $variationId = 'variation-' . $referencedId;
            }

            $imageUrl = null;
            $cover = $lineItem->getCover();
            if ($cover !== null) {
                $imageUrl = $cover->getUrl();
            }

            $unitPrice = 0.0;
            $price = $lineItem->getPrice();
            if ($price !== null) {
                $unitPrice = round($price->getUnitPrice(), 2);
            }

            // Use SEO URL if available, fall back to /detail/
            $productUrl = $seoUrlMap[$referencedId] ?? ($baseUrl . '/detail/' . $referencedId);

            $items[] = [
                'product_id' => 'product-' . $productId,
                'variation_id' => $variationId,
                'name' => $lineItem->getLabel() ?? '',
                'quantity' => $lineItem->getQuantity(),
                'unit_price' => $unitPrice,
                'image_url' => $imageUrl,
                'product_url' => $productUrl,
            ];

            $itemCount += $lineItem->getQuantity();
        }

        $currency = $context->getCurrency();

        return [
            'items' => $items,
            'item_count' => $itemCount,
            'total' => round($cart->getPrice()->getTotalPrice(), 2),
            'currency' => $currency->getIsoCode(),
        ];
    }

    /**
     * @param string[] $productIds
     * @return array<string, string> Map of product ID => full SEO URL
     */
    private function loadProductSeoUrls(array $productIds, SalesChannelContext $context): array
    {
        if (empty($productIds)) {
            return [];
        }

        $baseUrl = $this->getBaseUrl();

        $criteria = new Criteria($productIds);
        $criteria->addAssociation('seoUrls');

        $products = $this->productRepository->search($criteria, $context->getContext());
        $map = [];

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $seoUrls = $product->getSeoUrls();
            if ($seoUrls === null || $seoUrls->count() === 0) {
                continue;
            }

            // Prefer canonical URL for this sales channel
            foreach ($seoUrls as $seoUrl) {
                if ($seoUrl->getSalesChannelId() === $context->getSalesChannelId() && $seoUrl->getIsCanonical()) {
                    $map[$product->getId()] = rtrim($baseUrl, '/') . '/' . ltrim($seoUrl->getSeoPathInfo(), '/');
                    continue 2;
                }
            }

            // Fallback to first SEO URL
            $seoUrl = $seoUrls->first();
            $map[$product->getId()] = rtrim($baseUrl, '/') . '/' . ltrim($seoUrl->getSeoPathInfo(), '/');
        }

        return $map;
    }

    private function getBaseUrl(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return '';
        }

        return $request->getSchemeAndHttpHost();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        $data = json_decode($content, true);

        return \is_array($data) ? $data : [];
    }

    private function resolveId(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(?:product|variation)-(.+)$/', $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }

    /**
     * If the given ID is a parent product with children, resolve it to a
     * single variant. Shopware does not allow adding parent products
     * directly to the cart.
     *
     * Returns null when the parent has more than one active child and the
     * caller must ask the user to pick one explicitly rather than the code
     * silently guessing (fail closed).
     */
    private function resolveToVariantIfParent(string $productId, SalesChannelContext $context): ?string
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('children');

        /** @var ProductEntity|null $product */
        $product = $this->productRepository->search($criteria, $context->getContext())->getEntities()->first();

        if ($product === null) {
            return $productId;
        }

        $children = $product->getChildren();
        if ($children === null || $children->count() === 0) {
            return $productId;
        }

        $activeChildren = [];
        foreach ($children as $child) {
            if ($child->getActive()) {
                $activeChildren[] = $child;
            }
        }

        if (\count($activeChildren) === 1) {
            return $activeChildren[0]->getId();
        }

        if (\count($activeChildren) > 1) {
            return null;
        }

        // No explicitly active children: fall back to the first child, as before.
        return $children->first()->getId();
    }

    private function findLineItemId(Cart $cart, string $productId): ?string
    {
        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getReferencedId() === $productId) {
                return $lineItem->getId();
            }
        }

        return null;
    }
}
