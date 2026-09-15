<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

/** What the far end of a signup link supplies. Validation of the shape belongs to the adapter. */
final readonly class CompleteSignupRequest
{
    public function __construct(
        public string $rawToken,
        public string $displayName,
        public string $plainPassword,
        public string $companyName,
        public string $countryCode,
        public string $timezone,
    ) {
    }
}
