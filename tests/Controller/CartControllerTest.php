<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Controller;

use Emporiqa\ShopwarePlugin\Controller\CartController;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Emporiqa\ShopwarePlugin\Tests\Support\EntityCollectionHelper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class CartControllerTest extends TestCase
{
    use EntityCollectionHelper;

    private CartService&MockObject $cartService;
    private LineItemFactoryRegistry&MockObject $lineItemFactory;
    private RequestStack&MockObject $requestStack;
    private ConfigServiceInterface&MockObject $config;
    private EntityRepository&MockObject $productRepository;
    private CartController $controller;

    protected function setUp(): void
    {
        $this->cartService = $this->createMock(CartService::class);
        $this->lineItemFactory = $this->createMock(LineItemFactoryRegistry::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->productRepository = $this->createMock(EntityRepository::class);

        $this->controller = new CartController(
            $this->cartService,
            $this->lineItemFactory,
            $this->requestStack,
            $this->config,
            $this->productRepository,
        );
    }

    public function testGetCartReturnsStandardEnvelopeWhenCartDisabled(): void
    {
        $context = $this->createSalesChannelContext('channel-1');
        $this->config->method('isCartEnabled')->with('channel-1')->willReturn(false);

        $response = $this->controller->getCart($context);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Cart operations are disabled.', $data['error']);
        $this->assertNull($data['checkoutUrl']);
        $this->assertNull($data['cart']);
    }

    public function testAddToCartReturnsStandardEnvelopeWhenCartDisabled(): void
    {
        $context = $this->createSalesChannelContext('channel-2');
        $this->config->method('isCartEnabled')->with('channel-2')->willReturn(false);

        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => 'product-abc']));

        $response = $this->controller->addToCart($request, $context);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Cart operations are disabled.', $data['error']);
    }

    public function testUpdateCartReturnsStandardEnvelopeWhenCartDisabled(): void
    {
        $context = $this->createSalesChannelContext('channel-upd-disabled');
        $this->config->method('isCartEnabled')->willReturn(false);

        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => 'product-abc', 'quantity' => 2]));

        $response = $this->controller->updateCart($request, $context);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Cart operations are disabled.', $data['error']);
        $this->assertNull($data['checkoutUrl']);
        $this->assertNull($data['cart']);
    }

    public function testRemoveFromCartReturnsStandardEnvelopeWhenCartDisabled(): void
    {
        $context = $this->createSalesChannelContext('channel-rem-disabled');
        $this->config->method('isCartEnabled')->willReturn(false);

        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => 'product-abc']));

        $response = $this->controller->removeFromCart($request, $context);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Cart operations are disabled.', $data['error']);
    }

    public function testClearCartReturnsStandardEnvelopeWhenCartDisabled(): void
    {
        $context = $this->createSalesChannelContext('channel-clear-disabled');
        $this->config->method('isCartEnabled')->willReturn(false);

        $response = $this->controller->clearCart($context);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Cart operations are disabled.', $data['error']);
    }

    public function testGetCheckoutUrlReturnsStandardEnvelopeWhenCartDisabled(): void
    {
        $context = $this->createSalesChannelContext('channel-checkout-disabled');
        $this->config->method('isCartEnabled')->willReturn(false);

        $response = $this->controller->getCheckoutUrl($context);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Cart operations are disabled.', $data['error']);
        $this->assertNull($data['checkoutUrl']);
        $this->assertNull($data['cart']);
    }

    // --- Quantity ceiling (J2) ---

    public function testAddToCartRejectsQuantityAboveMaximum(): void
    {
        $context = $this->createSalesChannelContext('channel-qty-add');
        $this->config->method('isCartEnabled')->willReturn(true);

        $request = new Request([], [], [], [], [], [], json_encode([
            'product_id' => 'product-abc',
            'quantity' => 10000,
        ]));

        $response = $this->controller->addToCart($request, $context);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Quantity exceeds the allowed maximum.', $data['error']);
    }

    public function testUpdateCartRejectsQuantityAboveMaximum(): void
    {
        $context = $this->createSalesChannelContext('channel-qty-update');
        $this->config->method('isCartEnabled')->willReturn(true);

        $request = new Request([], [], [], [], [], [], json_encode([
            'product_id' => 'product-abc',
            'quantity' => 10000,
        ]));

        $response = $this->controller->updateCart($request, $context);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Quantity exceeds the allowed maximum.', $data['error']);
    }

    public function testAddToCartReturnsErrorForEmptyProductId(): void
    {
        $context = $this->createSalesChannelContext('channel-3');
        $this->config->method('isCartEnabled')->with('channel-3')->willReturn(true);

        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => '']));

        $response = $this->controller->addToCart($request, $context);

        $this->assertInstanceOf(JsonResponse::class, $response);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('No product ID provided.', $data['error']);
        $this->assertNull($data['cart']);
    }

    public function testAddToCartReturnsErrorForMissingProductId(): void
    {
        $context = $this->createSalesChannelContext('channel-4');
        $this->config->method('isCartEnabled')->with('channel-4')->willReturn(true);

        $request = new Request([], [], [], [], [], [], json_encode([]));

        $response = $this->controller->addToCart($request, $context);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('No product ID provided.', $data['error']);
    }

    public function testAddToCartReturnsErrorForInvalidUuid(): void
    {
        $context = $this->createSalesChannelContext('channel-5');
        $this->config->method('isCartEnabled')->with('channel-5')->willReturn(true);

        $request = new Request([], [], [], [], [], [], json_encode([
            'product_id' => 'not-a-valid-uuid',
        ]));

        $response = $this->controller->addToCart($request, $context);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Invalid product ID.', $data['error']);
    }

    public function testAddToCartReturnsErrorForInvalidUuidWithPrefix(): void
    {
        $context = $this->createSalesChannelContext('channel-6');
        $this->config->method('isCartEnabled')->with('channel-6')->willReturn(true);

        $request = new Request([], [], [], [], [], [], json_encode([
            'product_id' => 'product-not-a-uuid',
        ]));

        $response = $this->controller->addToCart($request, $context);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Invalid product ID.', $data['error']);
    }

    public function testAddToCartResolvesProductPrefix(): void
    {
        $context = $this->createSalesChannelContext('channel-7');
        $this->config->method('isCartEnabled')->with('channel-7')->willReturn(true);

        $request = new Request([], [], [], [], [], [], json_encode([
            'product_id' => 'product-invalid',
        ]));

        $response = $this->controller->addToCart($request, $context);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Invalid product ID.', $data['error']);
    }

    public function testAddToCartWithEmptyBody(): void
    {
        $context = $this->createSalesChannelContext('channel-8');
        $this->config->method('isCartEnabled')->with('channel-8')->willReturn(true);

        $request = new Request([], [], [], [], [], [], '');

        $response = $this->controller->addToCart($request, $context);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('No product ID provided.', $data['error']);
    }

    public function testAddToCartWithInvalidJsonBody(): void
    {
        $context = $this->createSalesChannelContext('channel-9');
        $this->config->method('isCartEnabled')->with('channel-9')->willReturn(true);

        $request = new Request([], [], [], [], [], [], 'not-json');

        $response = $this->controller->addToCart($request, $context);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('No product ID provided.', $data['error']);
    }

    // --- Fail-closed variant selection (J3) ---

    public function testAddToCartRejectsAmbiguousParentWithMultipleActiveChildren(): void
    {
        $context = $this->createSalesChannelContext('channel-ambiguous');
        $this->config->method('isCartEnabled')->willReturn(true);

        $parentId = Uuid::randomHex();

        $child1Id = Uuid::randomHex();
        $child1 = $this->createMock(ProductEntity::class);
        $child1->method('getUniqueIdentifier')->willReturn($child1Id);
        $child1->method('getId')->willReturn($child1Id);
        $child1->method('getActive')->willReturn(true);

        $child2Id = Uuid::randomHex();
        $child2 = $this->createMock(ProductEntity::class);
        $child2->method('getUniqueIdentifier')->willReturn($child2Id);
        $child2->method('getId')->willReturn($child2Id);
        $child2->method('getActive')->willReturn(true);

        $parent = $this->createMock(ProductEntity::class);
        $parent->method('getChildren')->willReturn(new ProductCollection([$child1, $child2]));

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($parent);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($parent));
        $this->productRepository->method('search')->willReturn($searchResult);

        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => 'product-' . $parentId]));

        $response = $this->controller->addToCart($request, $context);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Please select a product variation.', $data['error']);
    }

    public function testAddToCartResolvesSingleActiveChildAutomatically(): void
    {
        $context = $this->createSalesChannelContext('channel-single-active');
        $this->config->method('isCartEnabled')->willReturn(true);

        $parentId = Uuid::randomHex();
        $childId = Uuid::randomHex();

        $child = $this->createMock(ProductEntity::class);
        $child->method('getUniqueIdentifier')->willReturn($childId);
        $child->method('getId')->willReturn($childId);
        $child->method('getActive')->willReturn(true);

        $inactiveChildId = Uuid::randomHex();
        $inactiveChild = $this->createMock(ProductEntity::class);
        $inactiveChild->method('getUniqueIdentifier')->willReturn($inactiveChildId);
        $inactiveChild->method('getId')->willReturn($inactiveChildId);
        $inactiveChild->method('getActive')->willReturn(false);

        $parent = $this->createMock(ProductEntity::class);
        $parent->method('getChildren')->willReturn(new ProductCollection([$inactiveChild, $child]));

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($parent);
        $searchResult->method('getEntities')->willReturn(self::entityCollection($parent));
        $this->productRepository->method('search')->willReturn($searchResult);

        $this->lineItemFactory->method('create')->willThrowException(new \RuntimeException('stop before cart service'));

        $request = new Request([], [], [], [], [], [], json_encode(['product_id' => 'product-' . $parentId]));

        $response = $this->controller->addToCart($request, $context);

        // Reaches the line-item creation step (rather than the ambiguous-
        // variation error) — confirms the single active child was resolved.
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Failed to add item to cart.', $data['error']);
    }

    /**
     * @return SalesChannelContext&MockObject
     */
    private function createSalesChannelContext(string $salesChannelId): SalesChannelContext&MockObject
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);

        return $context;
    }
}
