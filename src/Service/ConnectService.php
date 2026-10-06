<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\EmporiqaIntegration;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * One-click "Connect to Emporiqa" handshake (OAuth-style PKCE).
 *
 * initiate(): mint state + PKCE verifier, persist them as a single-use
 * pending nonce in the system config, and build the /connect/start URL.
 * exchange(): verify state, claim the nonce, then trade the one-time
 * code + verifier for {store_id, webhook_secret, webhook_url} server-to-server.
 *
 * While the exchange is in flight Emporiqa proves the shop origin by asking
 * the verify action for HMAC(verifier, nonce) (ActionController), so the
 * claimed nonce is kept, marked "exchanging", until the exchange returns.
 */
class ConnectService implements ConnectServiceInterface
{
    private const PENDING_KEY = 'EmporiqaIntegration.config.connectPending';
    private const CONFIG_PREFIX = 'EmporiqaIntegration.config.';
    private const PENDING_TTL_SECONDS = 900;
    private const RETURN_PATH = '/admin#/emporiqa/connect/callback';
    private const VERIFIER_LENGTH = 64;
    private const VERIFIER_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    private const EXCHANGING_TTL_SECONDS = 120;

    /** Path of the rule endpoints under a storefront domain; Emporiqa appends actions/<rule>. */
    public const ACTIONS_PATH = '/emporiqa/';

    private readonly Client $client;

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly ConfigServiceInterface $config,
        private readonly LoggerInterface $logger,
        ?Client $client = null,
        private readonly ?Connection $connection = null,
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

        $raw = $this->readPendingRaw();
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

        // Single-use: a replayed callback or a second tab finds it claimed.
        $alreadyUsed = ['success' => false, 'error' => 'This connection link was already used. Click Connect to start again.'];
        if (isset($pending['exchangingAt'])) {
            return $alreadyUsed;
        }
        $pending['exchangingAt'] = time();
        if (!$this->claimPending($raw, json_encode($pending, \JSON_THROW_ON_ERROR))) {
            return $alreadyUsed;
        }

        try {
            $response = $this->client->request('POST', $this->baseUrl() . '/connect/exchange', [
                'json' => [
                    'code' => $code,
                    'code_verifier' => $pending['verifier'],
                    'shop_origin' => $pending['origin'],
                    'actions_base_url' => $this->actionsBaseUrl($pending['origin']),
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Emporiqa-Shopware/' . EmporiqaIntegration::PLUGIN_VERSION,
                ],
                'connect_timeout' => 5,
                // Emporiqa's origin proof runs inside the exchange (at most 4 s);
                // past this timeout the shop would keep its old secret while
                // Emporiqa has already issued a new one.
                'timeout' => 20,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('[Emporiqa] Connect exchange request failed: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Could not reach Emporiqa: ' . $e->getMessage()];
        } finally {
            $this->systemConfig->delete(self::PENDING_KEY);
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
        $this->config->saveRulesStatus(\is_array($parsed) ? $parsed : []);

        $this->logger->info('[Emporiqa] Shop connected via one-click connect (store id ' . $storeId . ').');

        return ['success' => true, 'storeId' => $storeId];
    }

    public function exchangingVerifier(string $state): ?string
    {
        $raw = $this->readPendingRaw();
        $pending = $raw !== '' ? json_decode($raw, true) : null;
        if (
            $state === ''
            || !\is_array($pending)
            || !\is_string($pending['state'] ?? null)
            || !\is_string($pending['verifier'] ?? null)
            || !isset($pending['exchangingAt'])
            || (time() - (int) $pending['exchangingAt']) > self::EXCHANGING_TTL_SECONDS
            || !hash_equals($pending['state'], $state)
        ) {
            return null;
        }

        return $pending['verifier'];
    }

    public function actionsBaseUrl(string $origin): string
    {
        $host = strtolower((string) parse_url($origin, \PHP_URL_HOST));
        $best = null;
        foreach ($this->storefrontDomainUrls() as $url) {
            $parts = parse_url($url);
            if (
                !\is_array($parts)
                || strtolower($parts['scheme'] ?? '') !== 'https'
                || strtolower($parts['host'] ?? '') !== $host
                || isset($parts['port'])
            ) {
                continue;
            }
            $path = rtrim($parts['path'] ?? '', '/');
            if ($best === null || \strlen($path) < \strlen($best)) {
                $best = $path;
            }
        }

        // No domain on this host: the bare origin, which only works when a
        // storefront domain is served there.
        return 'https://' . $host . ($best ?? '') . self::ACTIONS_PATH;
    }

    /**
     * Mark the pending handshake as exchanging, only if it is still exactly
     * the one read: of two callbacks racing with the same state, one wins.
     */
    private function claimPending(string $expected, string $claimed): bool
    {
        if ($this->connection === null) {
            $this->systemConfig->set(self::PENDING_KEY, $claimed);

            return true;
        }

        return $this->connection->executeStatement(
            'UPDATE `system_config` SET `configuration_value` = :claimed, `updated_at` = :now'
            . ' WHERE `configuration_key` = :key AND `sales_channel_id` IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(`configuration_value`, \'$._value\')) = :expected',
            [
                'claimed' => json_encode(['_value' => $claimed], \JSON_THROW_ON_ERROR),
                'now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'key' => self::PENDING_KEY,
                'expected' => $expected,
            ],
        ) === 1;
    }

    /**
     * The pending handshake straight from the database: the verify request
     * runs in another PHP process while the exchange waits, and must not
     * read a cached copy from before the claim.
     */
    private function readPendingRaw(): string
    {
        if ($this->connection === null) {
            return (string) $this->systemConfig->get(self::PENDING_KEY);
        }
        $stored = $this->connection->fetchOne(
            'SELECT `configuration_value` FROM `system_config` WHERE `configuration_key` = :key AND `sales_channel_id` IS NULL',
            ['key' => self::PENDING_KEY],
        );
        $decoded = \is_string($stored) ? json_decode($stored, true) : null;

        return \is_array($decoded) && \is_string($decoded['_value'] ?? null) ? $decoded['_value'] : '';
    }

    /**
     * URLs of the domains of active storefront sales channels.
     *
     * @return list<string>
     */
    private function storefrontDomainUrls(): array
    {
        if ($this->connection === null) {
            return [];
        }
        try {
            $urls = $this->connection->fetchFirstColumn(
                'SELECT d.`url` FROM `sales_channel_domain` d'
                . ' INNER JOIN `sales_channel` s ON s.`id` = d.`sales_channel_id`'
                . ' WHERE s.`active` = 1 AND s.`type_id` = :type',
                ['type' => hex2bin(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Emporiqa] Could not read the storefront domains: ' . $e->getMessage());

            return [];
        }

        return array_values(array_filter($urls, 'is_string'));
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
