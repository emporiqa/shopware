<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Controller;

use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SignatureHelper;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The signed customer token for the chat widget, fetched by the storefront
 * script when the chat opens and handed to the widget on a MessageChannel
 * port. Never cached and never written into the page or a URL, so no page
 * cache or log can hand one customer's identity to another.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class UserTokenController extends StorefrontController
{
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie'];

    public function __construct(
        private readonly ConfigServiceInterface $config,
    ) {
    }

    // GET stays only for storefront bundles compiled before 1.3.0 (themes not
    // yet recompiled after the update); drop it in 1.4.0, POST only.
    #[Route(path: '/emporiqa/api/user-token', name: 'frontend.emporiqa.user.token', methods: ['GET', 'POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => false, '_httpCache' => false])]
    public function getToken(SalesChannelContext $context): JsonResponse
    {
        if (!$this->config->isCartEnabled($context->getSalesChannelId())) {
            return new JsonResponse(['error' => 'Cart API is disabled'], Response::HTTP_NOT_FOUND, self::HEADERS);
        }

        $customer = $context->getCustomer();
        $secret = $this->config->getWebhookSecret($context->getSalesChannelId());
        $storeId = $this->config->getStoreId($context->getSalesChannelId());

        // A guest checkout keeps its customer record in the session, but a
        // guest is not signed in: no token, so Emporiqa never treats them as
        // a customer whose orders an id alone proves.
        if ($customer === null || $customer->getGuest() || $secret === '' || $storeId === '') {
            return new JsonResponse(['token' => null], Response::HTTP_OK, self::HEADERS);
        }

        return new JsonResponse(
            ['token' => SignatureHelper::generateUserToken($customer->getId(), $secret, $storeId)],
            Response::HTTP_OK,
            self::HEADERS,
        );
    }
}
