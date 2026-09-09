<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;

final readonly class MeUser
{
    public function __construct(
        #[ApiProperty(required: true)] public string $id,
        #[ApiProperty(required: true)] public string $email,
        #[ApiProperty(required: true)] public string $displayName,
        #[ApiProperty(required: true)] public string $locale,
        #[ApiProperty(required: true)] public bool $isPlatformOperator,
    ) {
    }
}
