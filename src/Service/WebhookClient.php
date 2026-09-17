<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Emporiqa\ShopwarePlugin\Exception\RateLimitException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class WebhookClient implements WebhookClientInterface
{
    private readonly Client $client;

    private ?string $lastError = null;

    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly LoggerInterface $logger,
        ?Client $client = null,
    ) {
        $this->client = $client ?? new Client();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $events
     */
    public function sendBatchEvents(array $events): bool
    {
        if (empty($events)) {
            return true;
        }

        return $this->doRequest(['events' => $events]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendEvent(string $type, array $data): bool
    {
        return $this->sendBatchEvents([
            ['type' => $type, 'data' => $data],
        ]);
    }

    public function startSyncSession(string $sessionId, string $entity): bool
    {
        return $this->sendEvent('sync.start', [
            'session_id' => $sessionId,
            'entity' => $entity,
        ]);
    }

    public function completeSyncSession(string $sessionId, string $entity): bool
    {
        return $this->sendEvent('sync.complete', [
            'session_id' => $sessionId,
            'entity' => $entity,
        ]);
    }

    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $events Real product events for dry run
     * @return array{success: bool, message: string, dry_run?: array<string, mixed>}
     */
    public function testConnection(array $events = []): array
    {
        if (!$this->config->isConfigured()) {
            return ['success' => false, 'message' => 'Plugin is not configured (missing Store ID or Webhook Secret).'];
        }

        if (empty($events)) {
            $events = [
                [
                    'type' => 'product.created',
                    'data' => [
                        'identification_number' => 'test-connection',
                        'sku' => 'TEST-001',
                        'channels' => [''],
                        'names' => ['' => ['en' => 'Connection Test']],
                        'descriptions' => ['' => ['en' => 'This is a connection test.']],
                        'links' => ['' => ['en' => 'https://example.com/test']],
                        'categories' => ['' => ['en' => ['Test']]],
                        'brands' => ['' => 'Test'],
                        'prices' => ['' => [['currency' => 'EUR', 'current_price' => 0.0, 'regular_price' => 0.0]]],
                        'availability_statuses' => ['' => 'available'],
                        'stock_quantities' => ['' => 0],
                        'images' => ['' => []],
                        'attributes' => ['' => ['en' => new \stdClass()]],
                        'parent_sku' => null,
                        'is_parent' => false,
                        'variation_attributes' => new \stdClass(),
                    ],
                ],
            ];
        }

        $url = $this->config->getFullWebhookUrl() . '?dry_run=true';
        $payload = ['events' => $events];

        try {
            $response = $this->doRequestRaw($url, $payload);
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            $parsed = json_decode($body, true);

            if (\in_array($statusCode, [200, 201, 202], true)) {
                return [
                    'success' => true,
                    'message' => 'Connection successful!',
                    'dry_run' => \is_array($parsed) ? $parsed : [],
                ];
            }

            if ($statusCode === 401) {
                return ['success' => false, 'message' => 'Invalid webhook secret. Check your credentials.'];
            }

            if ($statusCode === 400) {
                return [
                    'success' => false,
                    'message' => $parsed['error'] ?? 'Validation failed.',
                    'dry_run' => \is_array($parsed) ? $parsed : [],
                ];
            }

            return ['success' => false, 'message' => 'Unexpected response (HTTP ' . $statusCode . ').'];
        } catch (\JsonException $e) {
            $this->logger->error('[Emporiqa] Connection test payload encoding failed: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Failed to encode test payload.'];
        } catch (GuzzleException $e) {
            $this->logger->error('[Emporiqa] Connection test failed: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    public static function generateSignature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = self::generateSignature($payload, $secret);

        return hash_equals($expected, $signature);
    }

    public static function generateUserToken(string $userId, string $webhookSecret): string
    {
        $payload = json_encode(['uid' => $userId, 'ts' => time()]);
        $encodedPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedPayload, $webhookSecret);

        return $encodedPayload . '.' . $signature;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function doRequest(array $payload, int $maxRetries = 3): bool
    {
        $url = $this->config->getFullWebhookUrl();

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->doRequestRaw($url, $payload);
                $statusCode = $response->getStatusCode();

                if (\in_array($statusCode, [200, 201, 202, 204], true)) {
                    return true;
                }

                if ($statusCode === 429) {
                    $body = (string) $response->getBody();
                    $this->logger->warning('[Emporiqa] Rate limit hit, message will be re-queued with delay.' . ($body !== '' ? ' ' . mb_substr($body, 0, 200) : ''));
                    throw new RateLimitException('Rate limit exceeded (HTTP 429).');
                }

                if ($statusCode >= 500) {
                    $body = (string) $response->getBody();
                    $this->lastError = $this->buildFriendlyError($body);
                    $this->logger->warning('[Emporiqa] Server error ' . $statusCode . ' (attempt ' . $attempt . '/' . $maxRetries . ')' . ($body !== '' ? ', ' . mb_substr($body, 0, 500) : ''));

                    if ($attempt < $maxRetries) {
                        usleep($attempt * 1_000_000);
                        continue;
                    }

                    return false;
                }

                $body = (string) $response->getBody();
                $this->lastError = $this->buildFriendlyError($body);
                $this->logger->warning('[Emporiqa] Unexpected status code: ' . $statusCode . ($body !== '' ? ', ' . mb_substr($body, 0, 500) : ''));

                return false;
            } catch (RateLimitException $e) {
                throw $e; // Propagate to handler for delayed re-dispatch
            } catch (\JsonException $e) {
                $this->lastError = $e->getMessage();
                $this->logger->error('[Emporiqa] Failed to encode payload: ' . $e->getMessage());

                return false;
            } catch (\RuntimeException $e) {
                $this->lastError = $e->getMessage();
                $this->logger->warning('[Emporiqa] ' . $e->getMessage());

                return false;
            } catch (GuzzleException $e) {
                $this->lastError = $e->getMessage();
                $this->logger->warning('[Emporiqa] Request failed (attempt ' . $attempt . '/' . $maxRetries . '): ' . $e->getMessage());

                if ($attempt < $maxRetries) {
                    usleep($attempt * 1_000_000);
                    continue;
                }

                return false;
            }
        }

        return false;
    }

    /**
     * Ensure fields that the API expects as JSON objects are not serialized as empty arrays.
     * After Symfony Messenger JSON round-trip, \stdClass becomes [], convert back to \stdClass.
     *
     * Structure:
     *   attributes[channel][language] = object (key-value pairs), empty = {}
     *   variation_attributes[channel][language] = list (string[]), empty = []
     *
     * Only `attributes` needs deep conversion. `variation_attributes` only needs
     * top-level and channel-level conversion (the language level is a list, not an object).
     */
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function ensureDictFields(array $payload): array
    {
        foreach ($payload['events'] ?? [] as $i => $event) {
            $data = $event['data'] ?? [];

            // attributes: all levels should be objects when empty
            if (\array_key_exists('attributes', $data)) {
                $value = $data['attributes'];
                if ($value === []) {
                    $data['attributes'] = new \stdClass();
                } elseif (\is_array($value)) {
                    foreach ($value as $channelKey => $channelData) {
                        if ($channelData === []) {
                            $value[$channelKey] = new \stdClass();
                        } elseif (\is_array($channelData)) {
                            foreach ($channelData as $langKey => $langData) {
                                if ($langData === []) {
                                    $channelData[$langKey] = new \stdClass();
                                }
                            }
                            $value[$channelKey] = $channelData;
                        }
                    }
                    $data['attributes'] = $value;
                }
            }

            // variation_attributes: top-level and channel-level are objects,
            // but language-level values are LISTS, do NOT convert those to stdClass
            if (\array_key_exists('variation_attributes', $data)) {
                $value = $data['variation_attributes'];
                if ($value === []) {
                    $data['variation_attributes'] = new \stdClass();
                } elseif (\is_array($value)) {
                    foreach ($value as $channelKey => $channelData) {
                        if ($channelData === []) {
                            $value[$channelKey] = new \stdClass();
                        }
                    }
                    $data['variation_attributes'] = $value;
                }
            }

            $payload['events'][$i]['data'] = $data;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function doRequestRaw(string $url, array $payload): ResponseInterface
    {
        $this->lastError = null;

        $secret = $this->config->getWebhookSecret();

        if ($url === '/' || $secret === '') {
            throw new \RuntimeException('Webhook not configured.');
        }

        $payload = $this->ensureDictFields($payload);
        $jsonPayload = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $signature = self::generateSignature($jsonPayload, $secret);

        return $this->client->request('POST', $url, [
            'body' => $jsonPayload,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Webhook-Signature' => $signature,
            ],
            'connect_timeout' => 5,
            'timeout' => 30,
            'http_errors' => false,
            // Never forward the signature/body to a redirect target.
            'allow_redirects' => false,
        ]);
    }

    /**
     * Parse a JSON error body for a human-readable message. Checks, in
     * order: error, detail, message, errors[0], hint, first non-empty wins.
     */
    private function buildFriendlyError(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            return null;
        }

        foreach (['error', 'detail', 'message'] as $key) {
            $value = $decoded[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        $errors = $decoded['errors'] ?? null;
        if (\is_array($errors) && isset($errors[0])) {
            $first = $errors[0];
            if (\is_string($first) && $first !== '') {
                return $first;
            }
            if (\is_array($first)) {
                foreach (['message', 'msg', 'detail'] as $key) {
                    $value = $first[$key] ?? null;
                    if (\is_string($value) && $value !== '') {
                        return $value;
                    }
                }
            }
        }

        $hint = $decoded['hint'] ?? null;
        if (\is_string($hint) && $hint !== '') {
            return $hint;
        }

        return null;
    }
}
