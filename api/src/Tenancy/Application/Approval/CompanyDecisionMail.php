<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Approval;

/** What an owner is told when an operator refuses or suspends their company: which one, and the reason if one was given. */
final readonly class CompanyDecisionMail
{
    public function __construct(
        public string $to,
        public string $companyName,
        public string $locale,
        public ?string $reason,
    ) {
    }
}
