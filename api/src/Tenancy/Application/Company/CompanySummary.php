<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** One company as the switcher shows it: where the user may work, and as what. */
final readonly class CompanySummary
{
    public function __construct(
        public string $companyId,
        public string $name,
        public string $status,
        public string $role,
        public bool $pinned = false,
        /** The id of the company's stored logo, which changes with the logo; null without one. */
        public ?string $logoVersion = null,
    ) {
    }
}
