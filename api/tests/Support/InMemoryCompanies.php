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

    public function ofId(\Symfony\Component\Uid\Uuid $id): ?Company
    {
        foreach ($this->companies as $company) {
            if ($company->getId()->equals($id)) {
                return $company;
            }
        }

        return null;
    }

    public function ofName(string $name): ?Company
    {
        foreach ($this->companies as $company) {
            if ($company->getName() === $name) {
                return $company;
            }
        }

        return null;
    }

    public function all(): array
    {
        return $this->companies;
    }

    public function ofStatus(string $status): array
    {
        return array_values(array_filter($this->companies, static fn (Company $company) => $company->getStatus() === $status));
    }

    public function save(Company $company): void
    {
        if (!\in_array($company, $this->companies, true)) {
            $this->companies[] = $company;
        }
    }
}
