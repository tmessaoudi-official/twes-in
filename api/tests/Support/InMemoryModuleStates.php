<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\ModuleRegistry\Domain\ModuleState;
use App\ModuleRegistry\Domain\ModuleStateRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryModuleStates implements ModuleStateRepository
{
    /** @var list<ModuleState> */
    public array $rows = [];

    public function ofCompany(Uuid $companyId): array
    {
        return array_values(array_filter($this->rows, static fn (ModuleState $row) => $row->getCompany()->getId()->equals($companyId)));
    }

    public function ofKeyInCompany(string $key, Uuid $companyId): ?ModuleState
    {
        foreach ($this->ofCompany($companyId) as $row) {
            if ($row->getKey() === $key) {
                return $row;
            }
        }

        return null;
    }

    public function save(ModuleState $state): void
    {
        if (!\in_array($state, $this->rows, true)) {
            $this->rows[] = $state;
        }
    }
}
