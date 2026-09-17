<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Emporiqa\ShopwarePlugin\EmporiqaIntegration;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * One-click "Connect to Emporiqa" handshake (OAuth-style PKCE).
 *
 * initiate(): mint state + PKCE verifier, persist them as a single-use
 * pending nonce in the system config, and build the /connect/start URL.
 * exchange(): verify state, consume the nonce, then trade the one-time
 * code + verifier for {store_id, webhook_secret, webhook_url} server-to-server.
 */
class ConnectService implements ConnectServiceInterface
{
    private const PENDING_KEY = 'EmporiqaIntegration.config.connectPending';
    private const CONFIG_PREFIX = 'EmporiqaIntegration.config.';
    private const PENDING_TTL_SECONDS = 900;
    private const RETURN_PATH = '/admin#/emporiqa/connect/callback';
    private const VERIFIER_LENGTH = 64;
    private const VERIFIER_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    private readonly Client $client;

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly ConfigServiceInterface $config,
        private readonly LoggerInterface $logger,
        ?Client $client = null,
    ) {
        $this->client = $client ?? new Client();
    }

    public function initiate(string $shopOrigin): string
    {
        $origin = $this->validateShopOrigin($shopOrigin);

        $state = bin2hex(random_bytes(32));
        $verifier = $this->randomVerifier();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->systemConfig->set(self::PENDING_KEY, json_encode([
            'state' => $state,
            'verifier' => $verifier,
            'origin' => $origin,
            'createdAt' => time(),
        ], \JSON_THROW_ON_ERROR));

        $shopName = trim((string) $this->systemConfig->get('core.basicInformation.shopName'));

        $params = [
            'platform' => 'shopware',
            'shop_origin' => $origin,
            'return_path' => self::RETURN_PATH,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'plugin_version' => mb_substr(EmporiqaIntegration::PLUGIN_VERSION, 0, 32),
            'shop_name' => mb_substr($shopName, 0, 128),
        ];

        return $this->baseUrl() . '/connect/start?' . http_build_query($params);
    }

    public function exchange(string $code, string $state): array
    {
        $code = trim($code);
        $state = trim($state);

        if ($code === '' || $state === '') {
            return ['success' => false, 'error' => 'Missing code or state.'];
        }

        $raw = (string) $this->systemConfig->get(self::PENDING_KEY);
        $pending = $raw !== '' ? json_decode($raw, true) : null;

        if (
            !\is_array($pending)
            || !isset($pending['state'], $pending['verifier'], $pending['origin'])
            || !\is_string($pending['state'])
            || !\is_string($pending['verifier'])
            || !\is_string($pending['origin'])
        ) {
            return ['success' => false, 'error' => 'No pending connect handshake. Click Connect to start again.'];
        }

        if (!hash_equals($pending['state'], $state)) {
            return ['success' => false, 'error' => 'State mismatch. Click Connect to start again.'];
        }

        if ((time() - (int) ($pending['createdAt'] ?? 0)) > self::PENDING_TTL_SECONDS) {
            $this->systemConfig->delete(self::PENDING_KEY);

            return ['success' => false, 'error' => 'The connection attempt expired. Click Connect to start again.'];
        }

        // Single-use: consume the nonce before the network call so a replayed
        // callback can never reuse the verifier, even if the exchange fails.
        $this->systemConfig->delete(self::PENDING_KEY);

        try {
            $response = $this->client->request('POST', $this->baseUrl() . '/connect/exchange', [
                'json' => [
                    'code' => $code,
                    'code_verifier' => $pending['verifier'],
                    'shop_origin' => $pending['origin'],
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Emporiqa-Shopware/' . EmporiqaIntegration::PLUGIN_VERSION,
                ],
                'connect_timeout' => 5,
                'timeout' => 15,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('[Emporiqa] Connect exchange request failed: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Could not reach Emporiqa: ' . $e->getMessage()];
        }

        $statusCode = $response->getStatusCode();
        $parsed = json_decode((string) $response->getBody(), true);

        if ($statusCode !== 200) {
            $error = (\is_array($parsed) && isset($parsed['error']) && \is_string($parsed['error']))
                ? $parsed['error']
                : 'Emporiqa rejected the connect request (HTTP ' . $statusCode . ').';
            $this->logger->warning('[Emporiqa] Connect exchange rejected (HTTP ' . $statusCode . '): ' . $error);

            return ['success' => false, 'error' => $error];
        }

        $storeId = \is_array($parsed) ? (string) ($parsed['store_id'] ?? '') : '';
        $webhookSecret = \is_array($parsed) ? (string) ($parsed['webhook_secret'] ?? '') : '';
        $webhookUrl = \is_array($parsed) ? (string) ($parsed['webhook_url'] ?? '') : '';

        if ($storeId === '' || $webhookSecret === '') {
            return ['success' => false, 'error' => 'Unexpected response from Emporiqa.'];
        }

        if ($webhookUrl !== '') {
            $normalizedUrl = $this->normalizeWebhookUrl($webhookUrl);
            if ($normalizedUrl === null) {
                $this->logger->warning('[Emporiqa] Connect exchange returned a webhook URL from an unexpected host.');

                return ['success' => false, 'error' => 'Refusing webhook URL from unexpected host.'];
            }
            $this->systemConfig->set(self::CONFIG_PREFIX . 'webhookUrl', $normalizedUrl);
        }

        $this->systemConfig->set(self::CONFIG_PREFIX . 'storeId', $storeId);
        $this->systemConfig->set(self::CONFIG_PREFIX . 'webhookSecret', $webhookSecret);

        $this->logger->info('[Emporiqa] Shop connected via one-click connect (store id ' . $storeId . ').');

        return ['success' => true, 'storeId' => $storeId];
    }

    /**
     * Mirror of the Emporiqa backend's _validate_shop_origin: https scheme,
     * plain hostname containing a dot, no port, no path/query/fragment/userinfo.
     */
    private function validateShopOrigin(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || \strlen($raw) > 512) {
            throw new \InvalidArgumentException('Shop origin is empty or too long.');
        }

        $parts = parse_url($raw);
        if (!\is_array($parts)) {
            throw new \InvalidArgumentException('Shop origin is not a valid URL.');
        }

        if (($parts['scheme'] ?? '') !== 'https') {
            throw new \InvalidArgumentException('Shop origin must use HTTPS.');
        }

        if (isset($parts['port'])) {
            throw new \InvalidArgumentException('Shop origin must not contain a port.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Shop origin must not contain credentials.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Shop origin must not contain a query or fragment.');
        }

        $path = parse_url($raw, \PHP_URL_PATH);
        if (\is_string($path) && $path !== '' && $path !== '/') {
            throw new \InvalidArgumentException('Shop origin must not contain a path.');
        }

        $host = strtolower($parts['host'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9.\-]{0,253}\.[a-z]{2,63}$/', $host)) {
            throw new \InvalidArgumentException('Shop origin hostname is invalid.');
        }

        return 'https://' . $host;
    }

    /**
     * 64 random characters from the base64url alphabet (RFC 7636 requires 43-128).
     */
    private function randomVerifier(): string
    {
        $verifier = '';
        for ($i = 0; $i < self::VERIFIER_LENGTH; $i++) {
            $verifier .= self::VERIFIER_ALPHABET[random_int(0, 63)];
        }

        return $verifier;
    }

    /**
     * Emporiqa base URL (scheme + host) derived from the configured webhook URL,
     * e.g. https://emporiqa.com/webhooks/sync/ -> https://emporiqa.com.
     */
    private function baseUrl(): string
    {
        $parts = parse_url($this->config->getWebhookUrl());
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https';
        $host = \is_array($parts) && !empty($parts['host']) ? $parts['host'] : 'emporiqa.com';
        $port = \is_array($parts) && isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * Accept only https URLs on the configured Emporiqa host, and strip the
     * trailing /<store_id>/ segment, the client re-appends the store id itself.
     */
    private function normalizeWebhookUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $allowedHost = strtolower((string) parse_url($this->baseUrl(), \PHP_URL_HOST));

        if (
            !\is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || strtolower((string) $parts['host']) !== $allowedHost
        ) {
            return null;
        }

        return (string) preg_replace('#/sync/[^/]+/?$#', '/sync/', $url);
    }
}
