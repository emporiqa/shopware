<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

interface ConnectServiceInterface
{
    /**
     * Start the one-click connect handshake: mint state + PKCE verifier,
     * persist them, and return the Emporiqa /connect/start URL to redirect to.
     *
     * @throws \InvalidArgumentException when the shop origin is not a valid https origin
     */
    public function initiate(string $shopOrigin): string;

    /**
     * Complete the handshake: verify state, consume the pending nonce, and
     * exchange the one-time code for credentials via /connect/exchange.
     *
     * @return array{success: bool, storeId?: string, error?: string}
     */
    public function exchange(string $code, string $state): array;

    /**
     * The PKCE verifier of the exchange in flight for $state, else null.
     * Only an exchange this shop started and is still waiting on answers
     * Emporiqa's origin proof.
     */
    public function exchangingVerifier(string $state): ?string;

    /**
     * Base URL of the ready-made rule endpoints, sent at connect and shown
     * as the Order status address: the shortest https storefront domain on
     * $origin's host plus /emporiqa/ (Emporiqa appends actions/<rule>).
     */
    public function actionsBaseUrl(string $origin): string;
}
