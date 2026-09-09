<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;

final readonly class MeCompany
{
    public function __construct(
        #[ApiProperty(required: true)] public string $id,
        #[ApiProperty(required: true)] public string $name,
        #[ApiProperty(required: true)] public string $countryCode,
        #[ApiProperty(required: true)] public string $currency,
        #[ApiProperty(required: true)] public string $locale,
        #[ApiProperty(required: true)] public string $timezone,
        #[ApiProperty(required: true)] public string $status,
        /** the user's role name in this company */
        #[ApiProperty(required: true)] public string $role,
    ) {
    }
}
