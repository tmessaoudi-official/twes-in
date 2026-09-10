<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mfa;

use App\Identity\Application\SecretCipher;

/**
 * libsodium's secretbox (XSalsa20-Poly1305), keyed from `APP_MFA_KEY` and deliberately not from `APP_SECRET`:
 * that one rotates for unrelated reasons, and rotating it would silently invalidate every enrolled
 * authenticator at once (ruling of 2026-09-10).
 *
 * The nonce is random per call and travels in front of the ciphertext, so the same secret never produces the
 * same row twice. Poly1305 makes an altered value fail to open rather than decrypt to rubbish.
 */
final class SodiumSecretCipher implements SecretCipher
{
    private readonly string $key;

    public function __construct(#[\SensitiveParameter] string $base64Key)
    {
        $key = base64_decode($base64Key, true);

        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            // Fail at construction: a short key found on the first enrolment is an incident, not a typo.
            throw new \InvalidArgumentException(\sprintf('APP_MFA_KEY must be %d bytes, base64 encoded.', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }

        $this->key = $key;
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $cipher): string
    {
        $raw = base64_decode($cipher, true);

        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('The stored secret is not a value this cipher produced.');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        if (false === $plain) {
            throw new \RuntimeException('The stored secret was not produced by this key, or was altered since.');
        }

        return $plain;
    }
}
