<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

final readonly class CompleteSignupOutcome
{
    public function __construct(
        public string $userId,
        public string $companyName,
        /** pending while an operator's approval is required, active otherwise */
        public string $companyStatus,
    ) {
    }
}
