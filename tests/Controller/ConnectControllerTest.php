<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Controller;

use Emporiqa\ShopwarePlugin\Controller\Admin\ConnectController;
use Emporiqa\ShopwarePlugin\Service\ConnectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConnectControllerTest extends TestCase
{
    private ConnectServiceInterface&MockObject $connectService;
    private ConnectController $controller;

    protected function setUp(): void
    {
        $this->connectService = $this->createMock(ConnectServiceInterface::class);
        $this->controller = new ConnectController($this->connectService);
    }

    private function jsonRequest(array $body): Request
    {
        return new Request([], [], [], [], [], [], json_encode($body));
    }

    public function testInitiateReturnsStartUrl(): void
    {
        $this->connectService
            ->expects($this->once())
            ->method('initiate')
            ->with('https://myshop.example.com')
            ->willReturn('https://emporiqa.com/connect/start?platform=shopware');

        $response = $this->controller->initiate($this->jsonRequest(['origin' => 'https://myshop.example.com']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('https://emporiqa.com/connect/start?platform=shopware', $data['url']);
    }

    public function testInitiateReturns400ForInvalidOrigin(): void
    {
        $this->connectService
            ->method('initiate')
            ->willThrowException(new \InvalidArgumentException('Shop origin must use HTTPS.'));

        $response = $this->controller->initiate($this->jsonRequest(['origin' => 'http://myshop.example.com']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Shop origin must use HTTPS.', $data['error']);
    }

    public function testInitiatePassesEmptyOriginForMissingBody(): void
    {
        $this->connectService
            ->expects($this->once())
            ->method('initiate')
            ->with('')
            ->willThrowException(new \InvalidArgumentException('Shop origin is empty or too long.'));

        $response = $this->controller->initiate(new Request());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testInitiateReturns500OnUnexpectedError(): void
    {
        $this->connectService
            ->method('initiate')
            ->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller->initiate($this->jsonRequest(['origin' => 'https://myshop.example.com']));

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testExchangeReturnsServiceResultOnSuccess(): void
    {
        $this->connectService
            ->expects($this->once())
            ->method('exchange')
            ->with('one-time-code', 'state-abc')
            ->willReturn(['success' => true, 'storeId' => 'store-42']);

        $response = $this->controller->exchange($this->jsonRequest([
            'code' => 'one-time-code',
            'state' => 'state-abc',
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('store-42', $data['storeId']);
    }

    public function testExchangeReturns400OnServiceFailure(): void
    {
        $this->connectService
            ->method('exchange')
            ->willReturn(['success' => false, 'error' => 'State mismatch. Click Connect to start again.']);

        $response = $this->controller->exchange($this->jsonRequest([
            'code' => 'one-time-code',
            'state' => 'wrong-state',
        ]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('State mismatch', $data['error']);
    }

    public function testExchangeReturns400ForMissingParams(): void
    {
        $this->connectService->expects($this->never())->method('exchange');

        $response = $this->controller->exchange($this->jsonRequest(['code' => 'only-code']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Missing code or state', $data['error']);
    }

    public function testActionsUrlIsGivenForAnHttpsAdmin(): void
    {
        $this->connectService->method('actionsBaseUrl')->with('https://shop.example')->willReturn('https://shop.example/emporiqa/');

        $response = $this->controller->actionsUrl(new Request([], [], [], [], [], [], (string) json_encode(['origin' => 'https://shop.example'])));

        $this->assertSame(['url' => 'https://shop.example/emporiqa/'], json_decode((string) $response->getContent(), true));
    }

    public function testActionsUrlIsEmptyOverHttp(): void
    {
        $this->connectService->expects($this->never())->method('actionsBaseUrl');

        $response = $this->controller->actionsUrl(new Request([], [], [], [], [], [], (string) json_encode(['origin' => 'http://localhost:8061'])));

        $this->assertSame('', json_decode((string) $response->getContent(), true)['url']);
    }
}
