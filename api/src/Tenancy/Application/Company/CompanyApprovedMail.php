<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** Tells an owner that their company is open: sent in the owner's own language. */
final readonly class CompanyApprovedMail
{
    public function __construct(
        public string $to,
        public string $companyName,
        public string $loginUrl,
        public string $locale,
    ) {
    }
}
