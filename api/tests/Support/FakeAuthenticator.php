<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;

/**
 * A platform authenticator in PHP: an ES256 key pair, "none" attestation, and the byte layout of WebAuthn Level 3
 * § 6.1 (authenticator data) and § 6.5 (attestation object). It answers the options the API sent with what a
 * browser's `PublicKeyCredential.toJSON()` would post back, so a functional test exercises the real validators.
 */
final class FakeAuthenticator
{
    public const int USER_PRESENT = 0x01;
    public const int USER_VERIFIED = 0x04;
    private const int ATTESTED_DATA = 0x40;

    public readonly string $credentialId;

    private \OpenSSLAsymmetricKey $key;

    public function __construct(
        private readonly string $origin = 'http://localhost:8090',
        private readonly string $rpId = 'localhost',
    ) {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (false === $key) {
            throw new \RuntimeException('openssl cannot create a P-256 key here.');
        }
        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    public function id(): string
    {
        return self::base64Url($this->credentialId);
    }

    /**
     * @param array<array-key, mixed> $options the creation options the API answered
     *
     * @return array<string, mixed>
     */
    public function register(array $options, int $flags = self::USER_PRESENT | self::USER_VERIFIED, int $signCount = 0, ?string $challenge = null, ?string $origin = null): array
    {
        $clientData = $this->clientData('webauthn.create', $challenge ?? self::challengeOf($options), $origin);
        $authData = hash('sha256', $this->rpId, true).\chr(($flags | self::ATTESTED_DATA) & 0xFF).pack('N', $signCount)
            .str_repeat("\0", 16).pack('n', \strlen($this->credentialId)).$this->credentialId.$this->coseKey();
        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            'id' => $this->id(),
            'rawId' => $this->id(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64Url($clientData),
                'attestationObject' => self::base64Url((string) $attestation),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $options the request options the API answered
     *
     * @return array<string, mixed>
     */
    public function assert(array $options, int $flags = self::USER_PRESENT | self::USER_VERIFIED, int $signCount = 0, ?string $challenge = null, ?string $origin = null): array
    {
        $clientData = $this->clientData('webauthn.get', $challenge ?? self::challengeOf($options), $origin);
        $authData = hash('sha256', $this->rpId, true).\chr($flags & 0xFF).pack('N', $signCount);

        if (!openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, \OPENSSL_ALGO_SHA256) || !\is_string($signature)) {
            throw new \RuntimeException('openssl cannot sign here.');
        }

        return [
            'id' => $this->id(),
            'rawId' => $this->id(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64Url($clientData),
                'authenticatorData' => self::base64Url($authData),
                'signature' => self::base64Url($signature),
            ],
        ];
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function clientData(string $type, string $challenge, ?string $origin): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin ?? $this->origin, 'crossOrigin' => false], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /** RFC 9053 EC2 key: kty 2, alg ES256 (-7), crv P-256 (1), then the coordinates. */
    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);
        $ec = \is_array($details) && \is_array($details['ec'] ?? null) ? $details['ec'] : [];
        $x = str_pad(\is_string($ec['x'] ?? null) ? $ec['x'] : '', 32, "\0", \STR_PAD_LEFT);
        $y = str_pad(\is_string($ec['y'] ?? null) ? $ec['y'] : '', 32, "\0", \STR_PAD_LEFT);

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));
    }

    /** @param array<array-key, mixed> $options */
    private static function challengeOf(array $options): string
    {
        $challenge = $options['challenge'] ?? null;
        if (!\is_string($challenge)) {
            throw new \LogicException('The options carry no challenge.');
        }

        return $challenge;
    }
}
