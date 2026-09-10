<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Authenticated encryption for the few secrets that are bearer credentials rather than hashes: a TOTP secret
 * cannot be stored as a digest, because verifying a code needs the secret itself (ruling of 2026-09-10).
 */
interface SecretCipher
{
    public function encrypt(string $plain): string;

    /** @throws \RuntimeException when the value was not produced by this key, or was altered since */
    public function decrypt(string $cipher): string;
}
