<?php

namespace justinholtweb\pwa\tests\unit;

use justinholtweb\pwa\push\Encryptor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Web push encryption and VAPID signing.
 *
 * The first test is the important one. A push payload that is encrypted incorrectly is still
 * accepted by the push service, still returns 201, and simply never appears on the device — there
 * is no error anywhere to read. The only way to know the derivation is right is to reproduce a
 * known-good result, so the test vector from RFC 8291 §5 is checked byte for byte.
 */
class EncryptorTest extends TestCase
{
    // RFC 8291 §5, "Push Message Encryption Example".
    private const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const UA_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    private const AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    private const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
    private const PLAINTEXT = 'When I grow up, I want to be a watermelon';
    private const EXPECTED = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public function testTheRfc8291TestVectorIsReproducedExactly(): void
    {
        $pem = Encryptor::importPrivateKey(self::AS_PUBLIC, self::AS_PRIVATE);

        $body = Encryptor::encrypt(
            self::PLAINTEXT,
            self::UA_PUBLIC,
            self::UA_AUTH,
            $pem,
            Encryptor::decode(self::SALT),
        );

        self::assertSame(self::EXPECTED, Encryptor::encode($body));
    }

    public function testAnImportedKeypairIsUsable(): void
    {
        self::assertTrue(Encryptor::isUsableKey(Encryptor::importPrivateKey(self::AS_PUBLIC, self::AS_PRIVATE)));
    }

    public function testGeneratedKeysAreTheRightShape(): void
    {
        $keys = Encryptor::generateKeys();

        // 65 bytes: the 0x04 uncompressed-point marker plus two 32-byte coordinates.
        self::assertSame(65, strlen(Encryptor::decode($keys['publicKey'])));
        self::assertStringStartsWith("\x04", Encryptor::decode($keys['publicKey']));
        self::assertTrue(Encryptor::isUsableKey($keys['privateKey']));
    }

    public function testTwoEncryptionsOfTheSamePayloadDiffer(): void
    {
        // A fresh ephemeral keypair and salt per message is what forward secrecy rests on. Two
        // identical ciphertexts would mean one of them is being reused.
        $first = Encryptor::encrypt('hello', self::UA_PUBLIC, self::UA_AUTH);
        $second = Encryptor::encrypt('hello', self::UA_PUBLIC, self::UA_AUTH);

        self::assertNotSame($first, $second);
    }

    public function testTheVapidHeaderIsAJwtForTheEndpointOrigin(): void
    {
        $keys = Encryptor::generateKeys();

        $header = Encryptor::vapidHeader(
            'https://fcm.googleapis.com/fcm/send/abc123',
            'mailto:someone@example.com',
            $keys['publicKey'],
            $keys['privateKey'],
            1_700_000_000,
        );

        self::assertStringStartsWith('vapid t=', $header);
        self::assertStringContainsString(', k=' . $keys['publicKey'], $header);

        preg_match('/^vapid t=([^,]+),/', $header, $matches);
        $parts = explode('.', $matches[1]);

        self::assertCount(3, $parts);

        $claims = json_decode(Encryptor::decode($parts[1]), true, 512, JSON_THROW_ON_ERROR);

        // The audience is the origin and nothing more. Including the path is rejected by push
        // services with an error that says only "invalid JWT".
        self::assertSame('https://fcm.googleapis.com', $claims['aud']);
        self::assertSame('mailto:someone@example.com', $claims['sub']);
        self::assertSame(1_700_000_000 + 43200, $claims['exp']);
    }

    public function testTheSignatureIsRawSixtyFourBytesNotDer(): void
    {
        $keys = Encryptor::generateKeys();

        // Signed repeatedly because the failure mode is intermittent: openssl emits a shorter DER
        // integer whenever r or s happens to have leading zero bytes, and a naive conversion then
        // produces a signature of the wrong length roughly one time in 256.
        for ($i = 0; $i < 50; $i++) {
            $header = Encryptor::vapidHeader(
                'https://push.example.com/' . $i,
                'mailto:someone@example.com',
                $keys['publicKey'],
                $keys['privateKey'],
            );

            preg_match('/^vapid t=([^,]+),/', $header, $matches);
            $parts = explode('.', $matches[1]);

            self::assertSame(64, strlen(Encryptor::decode($parts[2])), "iteration {$i}");
        }
    }

    public function testTheSignatureVerifiesAgainstThePublicKey(): void
    {
        $keys = Encryptor::generateKeys();
        $header = Encryptor::vapidHeader('https://push.example.com/x', 'mailto:a@b.com', $keys['publicKey'], $keys['privateKey']);

        preg_match('/^vapid t=([^,]+),/', $header, $matches);
        [$jwtHeader, $claims, $signature] = explode('.', $matches[1]);

        $raw = Encryptor::decode($signature);
        $r = ltrim(substr($raw, 0, 32), "\x00");
        $s = ltrim(substr($raw, 32), "\x00");

        if (ord($r[0]) > 0x7f) {
            $r = "\x00" . $r;
        }

        if (ord($s[0]) > 0x7f) {
            $s = "\x00" . $s;
        }

        $der = "\x30" . chr(4 + strlen($r) + strlen($s))
            . "\x02" . chr(strlen($r)) . $r
            . "\x02" . chr(strlen($s)) . $s;

        $public = openssl_pkey_get_details(openssl_pkey_get_private($keys['privateKey']))['key'];

        self::assertSame(1, openssl_verify($jwtHeader . '.' . $claims, $der, $public, OPENSSL_ALGO_SHA256));
    }

    public function testABadSubscriptionKeyIsRejectedRatherThanEncryptedWrongly(): void
    {
        $this->expectException(RuntimeException::class);

        Encryptor::encrypt('hello', Encryptor::encode(random_bytes(20)), self::UA_AUTH);
    }

    public function testAnAuthSecretOfTheWrongLengthIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        Encryptor::encrypt('hello', self::UA_PUBLIC, Encryptor::encode(random_bytes(8)));
    }

    public function testBase64UrlRoundTripsBinary(): void
    {
        $bytes = random_bytes(129);

        self::assertSame($bytes, Encryptor::decode(Encryptor::encode($bytes)));
        self::assertStringNotContainsString('=', Encryptor::encode($bytes));
        self::assertStringNotContainsString('+', Encryptor::encode($bytes));
    }
}
