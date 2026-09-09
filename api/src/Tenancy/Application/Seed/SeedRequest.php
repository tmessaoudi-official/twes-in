<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Seed;

final readonly class SeedRequest
{
    public function __construct(
        public string $operatorEmail,
        public ?string $operatorPassword,
        public string $operatorName,
        public string $companyName,
        public string $country,
        public string $currency,
        public string $locale,
        public string $timezone,
    ) {
    }
}
