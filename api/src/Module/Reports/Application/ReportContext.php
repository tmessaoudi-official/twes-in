<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

use App\Tenancy\Domain\Model\Company;

/**
 * Who reads a report, for which company, narrowed to which establishment, and on which day of the company's calendar: an
 * age bracket counts from today, a column of costs is shown to whoever may read costs. A plain value, as a port carries.
 */
final readonly class ReportContext
{
    /** @param \Closure(string): bool $may whether the member's role in the company grants a permission */
    public function __construct(
        public Company $company,
        public \DateTimeImmutable $today,
        public \Closure $may,
        public ?string $establishmentId = null,
    ) {
    }
}
