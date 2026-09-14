<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Domain;

use Symfony\Component\Uid\Uuid;

interface ModuleStateRepository
{
    /** @return list<ModuleState> the modules the company has switched, on or off */
    public function ofCompany(Uuid $companyId): array;

    public function ofKeyInCompany(string $key, Uuid $companyId): ?ModuleState;

    public function save(ModuleState $state): void;
}
