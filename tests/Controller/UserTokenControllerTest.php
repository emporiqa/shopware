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
        $customer->setGuest(false);

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

    /**
     * After a guest checkout Shopware keeps the guest's customer record in the
     * session. A guest is not signed in, so no token is issued for it: the
     * platform would otherwise treat the guest record as a signed-in customer.
     */
    public function testAGuestCheckoutSessionGetsNoToken(): void
    {
        $customer = new CustomerEntity();
        $customer->setId('0190bbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $customer->setGuest(true);

        $response = $this->controller()->getToken($this->context($customer));

        $this->assertSame(['token' => null], json_decode((string) $response->getContent(), true));
        $this->assertNoStore($response->headers->get('Cache-Control'));
    }

    /**
     * The storefront asks for a token only when the page says the shopper is
     * signed in; a guest checkout session must not say so.
     *
     * @return iterable<string, array{0: ?array<string, bool>, 1: bool}>
     */
    public static function storefrontCustomers(): iterable
    {
        yield 'no customer' => [null, false];
        yield 'guest checkout' => [['guest' => true], false];
        yield 'customer account' => [['guest' => false], true];
    }

    /**
     * @param array<string, bool>|null $customer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storefrontCustomers')]
    public function testTheStorefrontMarksOnlyAccountCustomersAsSignedIn(?array $customer, bool $loggedIn): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../src/Resources/views/storefront/base.html.twig');
        $template = (string) preg_replace('/{%\s*sw_extends[^%]*%}/', '', $template);
        $template = str_replace('{{ parent() }}', '', $template);
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['base' => $template]), ['strict_variables' => false]);

        $html = $twig->render('base', ['emporiqaConfig' => ['storeId' => 'st_1'], 'context' => ['customer' => $customer]]);

        $this->assertSame(1, preg_match('/data-emporiqa-widget-options="([^"]*)"/', $html, $match));
        $options = json_decode(html_entity_decode($match[1], \ENT_QUOTES), true);
        $this->assertSame($loggedIn, $options['loggedIn']);
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
