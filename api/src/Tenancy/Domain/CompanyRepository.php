<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Symfony\Component\Uid\Uuid;

interface CompanyRepository
{
    public function ofId(Uuid $id): ?Company;

    public function ofName(string $name): ?Company;

    public function save(Company $company): void;
}
