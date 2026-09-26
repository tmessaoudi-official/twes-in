<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\ModuleRegistry\Domain\ModuleInterest;
use App\ModuleRegistry\Domain\ModuleInterestRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryModuleInterests implements ModuleInterestRepository
{
    /** @var list<ModuleInterest> */
    public array $rows = [];

    public function waitingOfCompany(Uuid $companyId): array
    {
        return array_values(array_filter($this->waiting(), static fn (ModuleInterest $row) => $row->getCompany()->getId()->equals($companyId)));
    }

    public function ofKeyInCompany(string $key, Uuid $companyId): ?ModuleInterest
    {
        foreach ($this->rows as $row) {
            if ($row->getKey() === $key && $row->getCompany()->getId()->equals($companyId)) {
                return $row;
            }
        }

        return null;
    }

    public function waiting(): array
    {
        return array_values(array_filter($this->rows, static fn (ModuleInterest $row) => !$row->isAnnounced()));
    }

    public function save(ModuleInterest $interest): void
    {
        if (!\in_array($interest, $this->rows, true)) {
            $this->rows[] = $interest;
        }
    }

    public function remove(ModuleInterest $interest): void
    {
        $this->rows = array_values(array_filter($this->rows, static fn (ModuleInterest $row) => $row !== $interest));
    }
}
