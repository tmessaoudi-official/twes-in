<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;

final class InMemoryCompanies implements CompanyRepository
{
    /** @var list<Company> */
    public array $companies = [];

    public function ofName(string $name): ?Company
    {
        foreach ($this->companies as $company) {
            if ($company->getName() === $name) {
                return $company;
            }
        }

        return null;
    }

    public function save(Company $company): void
    {
        if (!\in_array($company, $this->companies, true)) {
            $this->companies[] = $company;
        }
    }
}
