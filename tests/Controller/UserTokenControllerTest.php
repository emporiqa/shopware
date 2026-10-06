<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Controller;

use Emporiqa\ShopwarePlugin\Controller\UserTokenController;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class UserTokenControllerTest extends TestCase
{
    public function testAGuestGetsNoToken(): void
    {
        $response = $this->controller()->getToken($this->context(null));

        $this->assertSame(['token' => null], json_decode((string) $response->getContent(), true));
        $this->assertNoStore($response->headers->get('Cache-Control'));
    }

    public function testASignedInCustomerGetsAStoreBoundToken(): void
    {
        $customer = new CustomerEntity();
        $customer->setId('0190aaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        $response = $this->controller()->getToken($this->context($customer));
        $token = json_decode((string) $response->getContent(), true)['token'];
        [$encoded, $mac] = explode('.', $token);
        $claims = json_decode((string) base64_decode(strtr($encoded, '-_', '+/')), true);

        $this->assertSame(hash_hmac('sha256', $encoded, 'secret'), $mac);
        $this->assertSame('0190aaaaaaaaaaaaaaaaaaaaaaaaaaaa', $claims['uid']);
        $this->assertSame('st_1', $claims['aud']);
        $this->assertNoStore($response->headers->get('Cache-Control'));
        $this->assertSame('Cookie', $response->headers->get('Vary'));
    }

    private function assertNoStore(?string $cacheControl): void
    {
        $this->assertStringContainsString('no-store', (string) $cacheControl);
        $this->assertStringContainsString('private', (string) $cacheControl);
    }

    private function controller(): UserTokenController
    {
        $config = $this->createMock(ConfigServiceInterface::class);
        $config->method('isCartEnabled')->willReturn(true);
        $config->method('getWebhookSecret')->willReturn('secret');
        $config->method('getStoreId')->willReturn('st_1');

        return new UserTokenController($config);
    }

    private function context(?CustomerEntity $customer): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('channel-1');
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }
}
