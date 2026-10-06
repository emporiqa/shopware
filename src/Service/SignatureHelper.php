<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

/**
 * Scheme-2 signatures (per-purpose HKDF keys bound to the public store id)
 * and the signed customer token.
 *
 * key    = HKDF-SHA256(secret, 32, info: "<label>:<store id>", salt: "emporiqa-v2")
 * header = t=<unix seconds>,v1=<hex HMAC-SHA256(key, t . "." . message)>
 *
 * Checked against the platform's tests/fixtures/signature_vectors.json.
 */
final class SignatureHelper
{
    public const SALT = 'emporiqa-v2';

    /** Sync webhooks, plugin to Emporiqa. */
    public const LABEL_INBOUND = 'plugin-to-emporiqa';

    /** Rule calls, Emporiqa to plugin. */
    public const LABEL_OUTBOUND = 'emporiqa-to-plugin';

    /** Signatures on our answers to rule calls. */
    public const LABEL_RESPONSE = 'response';

    public const MAX_SKEW_SECONDS = 300;

    /**
     * Emporiqa sends two v1 values while a store changes its secret (new and
     * old), one otherwise; the cap leaves one spare. More is refused rather
     * than hashed.
     */
    public const MAX_SIGNATURES = 3;

    /**
     * @return string 32 raw bytes
     */
    public static function deriveKey(string $secret, string $label, string $storeId): string
    {
        return hash_hkdf('sha256', $secret, 32, $label . ':' . $storeId, self::SALT);
    }

    public static function buildHeader(string $key, string $message, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $message, $key);
    }

    /**
     * Exactly one t (ASCII digits) and one to MAX_SIGNATURES v1 values (64
     * lowercase hex each); unknown names are ignored, a malformed v1 refuses
     * the whole header.
     *
     * @return array{0: int, 1: list<string>}|null
     */
    public static function parseHeader(string $header): ?array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (\count($pair) !== 2) {
                return null;
            }
            if ($pair[0] === 't') {
                if ($timestamp !== null || !preg_match('/^[0-9]{1,12}$/D', $pair[1])) {
                    return null;
                }
                $timestamp = (int) $pair[1];
            } elseif ($pair[0] === 'v1') {
                if (\count($signatures) >= self::MAX_SIGNATURES || !preg_match('/^[0-9a-f]{64}$/D', $pair[1])) {
                    return null;
                }
                $signatures[] = $pair[1];
            }
        }
        if ($timestamp === null || $signatures === []) {
            return null;
        }

        return [$timestamp, $signatures];
    }

    /**
     * Valid when ANY v1 matches: during a secret change Emporiqa signs with
     * both secrets, and this shop holds one.
     *
     * @return string 'ok', 'signature' or 'expired'
     */
    public static function verifyHeader(
        string $header,
        string $body,
        string $secret,
        string $storeId,
        string $label,
        ?int $now = null,
    ): string {
        $parsed = self::parseHeader($header);
        if ($parsed === null || $secret === '' || $storeId === '') {
            return 'signature';
        }
        [$timestamp, $signatures] = $parsed;
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, self::deriveKey($secret, $label, $storeId));
        $matched = false;
        // No early exit, so the time taken does not say which position matched.
        foreach ($signatures as $signature) {
            $matched = hash_equals($expected, $signature) || $matched;
        }
        if (!$matched) {
            return 'signature';
        }
        if (abs(($now ?? time()) - $timestamp) > self::MAX_SKEW_SECONDS) {
            return 'expired';
        }

        return 'ok';
    }

    /**
     * Customer token for the chat widget: base64url(payload).hex(HMAC(raw secret)).
     * `aud` binds it to this Emporiqa store. Never write it into page HTML or a
     * URL; UserTokenController serves it uncached.
     */
    public static function generateUserToken(string $userId, string $webhookSecret, string $storeId): string
    {
        $payload = (string) json_encode(['uid' => $userId, 'ts' => time(), 'aud' => $storeId]);
        $encodedPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return $encodedPayload . '.' . hash_hmac('sha256', $encodedPayload, $webhookSecret);
    }
}
