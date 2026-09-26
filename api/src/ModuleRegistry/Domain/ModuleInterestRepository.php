<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Domain;

use Symfony\Component\Uid\Uuid;

interface ModuleInterestRepository
{
    /** @return list<ModuleInterest> what the company asked for and was not yet told about */
    public function waitingOfCompany(Uuid $companyId): array;

    public function ofKeyInCompany(string $key, Uuid $companyId): ?ModuleInterest;

    /** @return list<ModuleInterest> every company's, not yet told about: read by the operator and at start, never for one company */
    public function waiting(): array;

    public function save(ModuleInterest $interest): void;

    public function remove(ModuleInterest $interest): void;
}
