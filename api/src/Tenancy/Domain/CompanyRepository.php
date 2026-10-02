<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

interface CompanyRepository
{
    public function ofId(Uuid $id): ?Company;

    public function ofName(string $name): ?Company;

    /** @return list<Company> every company, by name */
    public function all(): array;

    /**
     * One page of the companies the platform's operators list, narrowed and sorted in the database.
     *
     * @return Page<Company>
     */
    public function search(CompanySearch $search, PageRequest $page): Page;

    public function save(Company $company): void;
}
