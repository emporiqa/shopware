<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Support;

use Emporiqa\ShopwarePlugin\Service\TransientFailureAwareInterface;
use Emporiqa\ShopwarePlugin\Service\WebhookClientInterface;

/**
 * What the plugin's WebhookClient implements, as one type to mock.
 */
interface TransientWebhookClient extends WebhookClientInterface, TransientFailureAwareInterface
{
}
