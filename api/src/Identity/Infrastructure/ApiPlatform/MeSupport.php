<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;

/**
 * How to reach the people who run the platform (`SupportSettings`), as the help panel offers it: each channel the
 * operators filled in, null where they did not, so the panel leaves it out rather than showing an empty line.
 */
final readonly class MeSupport
{
    public function __construct(
        #[ApiProperty(required: true)] public ?string $phone = null,
        #[ApiProperty(required: true)] public ?string $whatsapp = null,
        #[ApiProperty(required: true)] public ?string $email = null,
    ) {
    }
}
