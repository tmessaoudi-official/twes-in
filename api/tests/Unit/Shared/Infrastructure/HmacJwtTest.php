<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Realtime\HmacJwt;
use PHPUnit\Framework\TestCase;

/**
 * The connection token Centrifugo verifies is an HS256 JWT. It is signed here without a library, so the signature is
 * pinned to the published vector of RFC 7515 Appendix A.1, not to a value this code produced.
 */
final class HmacJwtTest extends TestCase
{
    private const string RFC_SIGNING_INPUT = 'eyJ0eXAiOiJKV1QiLA0KICJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ';
    private const string RFC_KEY_BASE64URL = 'AyM1SysPpbyDfgZld3umj1qzKObwVMkoqQ-EstJQLr_T-1qS0gZH75aKtMN3Yj0iPS4hcgUuTwjAzZr1Z9CAow';
    private const string RFC_SIGNATURE = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    public function testTheSignatureMatchesTheRfc7515Vector(): void
    {
        $key = HmacJwt::base64UrlDecode(self::RFC_KEY_BASE64URL);

        self::assertSame(self::RFC_SIGNATURE, HmacJwt::signature(self::RFC_SIGNING_INPUT, $key));
    }

    public function testATokenIsHeaderPayloadSignatureWithTheHs256Header(): void
    {
        $token = HmacJwt::encode(['sub' => 'u-1', 'exp' => 1_900_000_000], 'a-secret-of-enough-length-000000');
        $parts = explode('.', $token);

        self::assertCount(3, $parts);
        self::assertSame(['alg' => 'HS256', 'typ' => 'JWT'], json_decode(HmacJwt::base64UrlDecode($parts[0]), true));
        self::assertSame(HmacJwt::signature($parts[0].'.'.$parts[1], 'a-secret-of-enough-length-000000'), $parts[2]);
        self::assertStringNotContainsString('=', $token);
    }

    public function testTheClaimsComeBackAsTheyWent(): void
    {
        $claims = ['sub' => '0199', 'exp' => 1_900_000_000, 'channels' => ['user:0199', 'company:42']];
        $payload = explode('.', HmacJwt::encode($claims, 'a-secret-of-enough-length-000000'))[1];

        self::assertSame($claims, json_decode(HmacJwt::base64UrlDecode($payload), true));
    }

    public function testAnotherKeyGivesAnotherSignature(): void
    {
        self::assertNotSame(
            HmacJwt::encode(['sub' => 'u-1'], 'a-secret-of-enough-length-000000'),
            HmacJwt::encode(['sub' => 'u-1'], 'a-secret-of-enough-length-000001'),
        );
    }

    public function testAShortSecretIsRefused(): void
    {
        // HS256 with a key shorter than the hash output is guessable offline from any one token.
        $this->expectException(\InvalidArgumentException::class);

        HmacJwt::encode(['sub' => 'u-1'], 'short');
    }
}
