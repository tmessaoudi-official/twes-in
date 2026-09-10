<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mfa;

use App\Identity\Application\TotpCodes;
use OTPHP\TOTP;

/**
 * The RFC 6238 adapter over spomky-labs/otphp.
 *
 * The library's own `verify()` takes a leeway in seconds and answers a bool, which cannot say which window
 * matched. The step loop here is the same arithmetic and does say, which is what the port needs for replay
 * refusal. Every comparison is `hash_equals`: a code is a secret, and a timing difference over six digits
 * is a real oracle.
 */
final class OtphpTotpCodes implements TotpCodes
{
    /** 160 bits, the RFC 4226 recommendation, which is 32 base32 characters. */
    private const int SECRET_BYTES = 20;

    /** One step either way: a person typing six digits routinely crosses the boundary, and phone clocks skew both ways. */
    private const int LEEWAY_STEPS = 1;

    public function generateSecret(): string
    {
        return TOTP::generate(secretSize: self::SECRET_BYTES)->getSecret();
    }

    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        if ('' === $account || '' === $issuer) {
            throw new \InvalidArgumentException('An authenticator entry needs both an account and an issuer to label it.');
        }

        $totp = self::totpFor($secret);
        $totp->setLabel($account);
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    public function codeAt(string $secret, \DateTimeImmutable $at): string
    {
        return self::totpFor($secret)->at(self::nonNegative($at->getTimestamp()));
    }

    public function verify(string $secret, string $code, \DateTimeImmutable $now): ?int
    {
        // Refused before any arithmetic: anything that is not six digits cannot be a code, and checking the
        // shape first keeps a malformed input from reaching the library at all.
        if (1 !== preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $totp = self::totpFor($secret);
        $period = $totp->getPeriod();
        $timestamp = $now->getTimestamp();

        for ($step = -self::LEEWAY_STEPS; $step <= self::LEEWAY_STEPS; ++$step) {
            if (hash_equals($totp->at(self::nonNegative($timestamp + $step * $period)), $code)) {
                return intdiv($timestamp, $period) + $step;
            }
        }

        return null;
    }

    /** An empty secret is a corrupt row, not a secret that happens to be short: say so rather than compute with it. */
    private static function totpFor(string $secret): TOTP
    {
        if ('' === $secret) {
            throw new \InvalidArgumentException('An empty string cannot be a TOTP secret.');
        }

        return TOTP::createFromSecret($secret);
    }

    /**
     * Time before the epoch is not a window anyone can be in; clamping keeps the leeway loop total.
     *
     * @return int<0, max>
     */
    private static function nonNegative(int $timestamp): int
    {
        return max(0, $timestamp);
    }
}
