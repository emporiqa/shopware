<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Controller\Admin;

use Emporiqa\ShopwarePlugin\Service\ConnectServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['system.plugin_maintain']])]
class ConnectController extends AbstractController
{
    public function __construct(
        private readonly ConnectServiceInterface $connectService,
    ) {
    }

    #[Route(path: '/api/_action/emporiqa/connect/initiate', name: 'api.action.emporiqa.connect.initiate', methods: ['POST'])]
    public function initiate(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $origin = (\is_array($body) && isset($body['origin']) && \is_string($body['origin']))
            ? $body['origin']
            : '';

        try {
            $url = $this->connectService->initiate($origin);

            return new JsonResponse(['url' => $url]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Failed to start the connect handshake: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/api/_action/emporiqa/connect/exchange', name: 'api.action.emporiqa.connect.exchange', methods: ['POST'])]
    public function exchange(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $code = (\is_array($body) && isset($body['code']) && \is_string($body['code'])) ? $body['code'] : '';
        $state = (\is_array($body) && isset($body['state']) && \is_string($body['state'])) ? $body['state'] : '';

        if ($code === '' || $state === '') {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing code or state.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->connectService->exchange($code, $state);

            return new JsonResponse(
                $result,
                $result['success'] ? JsonResponse::HTTP_OK : JsonResponse::HTTP_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to complete the connect handshake: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The Order status address the settings page shows: the actions base
     * URL for the admin's own origin, the same one connect sends.
     */
    #[Route(path: '/api/_action/emporiqa/actions-url', name: 'api.action.emporiqa.actions-url', methods: ['POST'])]
    public function actionsUrl(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $origin = (\is_array($body) && \is_string($body['origin'] ?? null)) ? $body['origin'] : '';
        $scheme = parse_url($origin, \PHP_URL_SCHEME);
        if ($scheme !== 'https' || !\is_string(parse_url($origin, \PHP_URL_HOST))) {
            return new JsonResponse(['url' => '', 'httpsRequired' => true]);
        }

        return new JsonResponse(['url' => $this->connectService->actionsBaseUrl($origin)]);
    }
}
