<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Realtime;

/**
 * An HS256 JSON Web Token in compact serialisation (RFC 7515 § 7.1, RFC 7519), which is all Centrifugo needs to
 * trust a connection. Signing only: this API never verifies a token, Centrifugo does. Small enough to own rather
 * than depend on, and pinned to the RFC's own test vector (HmacJwtTest).
 */
final class HmacJwt
{
    /** HMAC SHA-256 keys shorter than the hash output make every issued token an offline guessing oracle. */
    private const int MINIMUM_KEY_BYTES = 32;

    /** @param array<string, mixed> $claims */
    public static function encode(array $claims, string $key): string
    {
        if (\strlen($key) < self::MINIMUM_KEY_BYTES) {
            throw new \InvalidArgumentException(\sprintf('An HS256 key needs at least %d bytes, %d given.', self::MINIMUM_KEY_BYTES, \strlen($key)));
        }

        $signingInput = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], \JSON_THROW_ON_ERROR))
            .'.'.self::base64UrlEncode(json_encode($claims, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        return $signingInput.'.'.self::signature($signingInput, $key);
    }

    public static function signature(string $signingInput, string $key): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $signingInput, $key, true));
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if (false === $decoded) {
            throw new \InvalidArgumentException('Not base64url.');
        }

        return $decoded;
    }
}
