<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * RFC 6238 time-based codes.
 *
 * `verify()` answers with the timestep a code belonged to rather than a bare bool, because that is the only
 * thing that makes a replay refusable: within its window a code stays arithmetically valid, so nothing but
 * the step distinguishes a second use from the first. The caller stores the step it accepted and refuses
 * anything not strictly newer.
 *
 * `codeAt()` is the inverse of `verify()` and exists so a test can produce a real code without a second
 * implementation of HMAC sitting beside the one under test. Nothing in production generates a code.
 */
interface TotpCodes
{
    /** A fresh base32 secret, 160 bits as RFC 4226 recommends. */
    public function generateSecret(): string;

    /** The `otpauth://` URI an authenticator reads from a QR code. */
    public function provisioningUri(string $secret, string $account, string $issuer): string;

    public function codeAt(string $secret, \DateTimeImmutable $at): string;

    /** @return int|null the timestep the code belonged to, or null when it is not a code for this secret */
    public function verify(string $secret, string $code, \DateTimeImmutable $now): ?int;
}
