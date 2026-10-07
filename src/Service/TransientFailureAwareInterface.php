<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

/**
 * A webhook client that can tell a delivery failure worth retrying later
 * (Emporiqa unreachable, timed out or answering 5xx) from one that would fail
 * the same way every time (a 4xx such as a wrong signature).
 *
 * Kept apart from WebhookClientInterface so decorators of that interface
 * keep working; a client without it is treated as transient.
 */
interface TransientFailureAwareInterface
{
    /**
     * Whether the most recent failed send is worth retrying later. False
     * after a success, a 4xx answer or a local error.
     */
    public function isLastFailureTransient(): bool;
}
