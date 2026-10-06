<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\SignatureHelper;
use PHPUnit\Framework\TestCase;

/**
 * SignatureHelper against the platform's scheme-2 vectors
 * (tests/fixtures/signature_vectors.json, byte-identical to the Emporiqa
 * repo's copy): every key, signature and header per label must match and
 * every negative vector must be refused. A rule call signed during a secret
 * change carries two v1 values and must verify holding either secret.
 */
class SignatureHelperTest extends TestCase
{
    private const LABELS = [
        SignatureHelper::LABEL_INBOUND,
        SignatureHelper::LABEL_OUTBOUND,
        SignatureHelper::LABEL_RESPONSE,
    ];

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/signature_vectors.json'), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testSaltMatchesThePlatform(): void
    {
        $this->assertSame(self::fixture()['salt'], SignatureHelper::SALT);
    }

    public function testVectorsProduceTheExpectedKeysSignaturesAndHeaders(): void
    {
        $seenLabels = [];
        foreach (self::fixture()['vectors'] as $i => $v) {
            $label = $v['label'];
            $this->assertContains($label, self::LABELS, "vector $i label");
            $seenLabels[$label] = true;
            // A response signature covers request_id . "." . body.
            $message = $label === SignatureHelper::LABEL_RESPONSE ? $v['request_id'] . '.' . $v['body'] : $v['body'];

            $key = SignatureHelper::deriveKey($v['secret'], $label, $v['store_id']);
            $this->assertSame($v['expected_key_hex'], bin2hex($key), "vector $i key");
            $header = SignatureHelper::buildHeader($key, $message, $v['t']);
            $this->assertSame($v['expected_header'], $header, "vector $i header");

            $this->assertSame('ok', SignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $label, $v['t']));
            $this->assertSame('ok', SignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $label, $v['t'] - 300));
            $this->assertSame('expired', SignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $label, $v['t'] + 301));
            foreach (array_diff(self::LABELS, [$label]) as $other) {
                $this->assertSame('signature', SignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $other, $v['t']));
            }
        }
        $this->assertCount(3, $seenLabels);
    }

    public function testNegativeVectorsAreRefused(): void
    {
        foreach (self::fixture()['negative_vectors'] as $v) {
            // Refused only by Emporiqa's single-v1 webhook verifier; a verifier of
            // Emporiqa's signatures accepts several (rotation vectors below).
            if (!empty($v['single_v1_only'])) {
                continue;
            }
            $this->assertNotSame(
                'ok',
                SignatureHelper::verifyHeader($v['header'], $v['body'], $v['secret'], $v['store_id'], SignatureHelper::LABEL_INBOUND, $v['now']),
                'negative: ' . $v['case'],
            );
        }
    }

    public function testRotationVectors(): void
    {
        $vectors = self::fixture()['rotation_vectors'];
        $this->assertNotEmpty($vectors);
        foreach ($vectors as $v) {
            foreach ($v['verify_with'] as $secret) {
                $this->assertSame(
                    $v['expect'],
                    SignatureHelper::verifyHeader($v['header'], $v['body'], $secret, $v['store_id'], $v['label'], $v['t']),
                    'rotation: ' . $v['case'],
                );
            }
        }
    }

    public function testUserTokenCarriesAudAndIsSignedWithTheRawSecret(): void
    {
        $token = SignatureHelper::generateUserToken('0190abcd', 'secret', 'st_7Kq2mXa9');
        [$encoded, $mac] = explode('.', $token);
        $claims = json_decode((string) base64_decode(strtr($encoded, '-_', '+/')), true);

        $this->assertSame(hash_hmac('sha256', $encoded, 'secret'), $mac);
        $this->assertSame('0190abcd', $claims['uid']);
        $this->assertSame('st_7Kq2mXa9', $claims['aud']);
        $this->assertIsInt($claims['ts']);
        $this->assertLessThan(5, abs($claims['ts'] - time()));
        // The shape embed.js accepts (CUSTOMER_TOKEN_SHAPE).
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+={0,2}\.[0-9a-f]{64}$/', $token);
    }
}
