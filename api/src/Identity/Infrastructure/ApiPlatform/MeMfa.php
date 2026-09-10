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
 * company it belongs to insists on one. `required && !enrolled` is the interstitial.
 */
final readonly class MeMfa
{
    public function __construct(
        #[ApiProperty(required: true)] public bool $enrolled,
        #[ApiProperty(required: true)] public bool $required,
    ) {
    }
}
