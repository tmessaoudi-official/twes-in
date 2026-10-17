<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Metadata\ApiProperty;

/** A country a visitor may try the demo in, and the company its sandbox holds; the screen names the country itself. */
final readonly class DemoCountry
{
    public function __construct(
        #[ApiProperty(required: true)] public string $code,
        #[ApiProperty(required: true)] public string $company,
    ) {
    }
}
