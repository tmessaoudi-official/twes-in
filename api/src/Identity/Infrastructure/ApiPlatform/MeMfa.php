<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The second-factor state the SPA needs to decide what to show: whether this account has one, and whether any
 * company it belongs to insists on one. `required && !enrolled` is the interstitial. `totp` and `passkeys` say which
 * factors it has, so the page offers what applies: replacing recovery codes takes an authenticator code, for one.
 */
final readonly class MeMfa
{
    public function __construct(
        #[ApiProperty(required: true)] public bool $enrolled,
        #[ApiProperty(required: true)] public bool $required,
        #[ApiProperty(required: true)] public bool $totp = false,
        #[ApiProperty(required: true)] public int $passkeys = 0,
    ) {
    }
}
