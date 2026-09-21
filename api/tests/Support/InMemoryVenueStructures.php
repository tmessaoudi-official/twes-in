<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Venue\Domain\VenueStructure;
use App\Venue\Domain\VenueStructureRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryVenueStructures implements VenueStructureRepository
{
    /** @var list<VenueStructure> */
    public array $structures = [];

    public function ofArea(Uuid $areaId): array
    {
        return array_values(array_filter($this->structures, static fn (VenueStructure $s) => $s->getArea()->getId()->equals($areaId)));
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?VenueStructure
    {
        return array_find($this->structures, static fn (VenueStructure $s) => $s->getId()->equals($id) && $s->getCompany()->getId()->equals($companyId));
    }

    public function save(VenueStructure $structure): void
    {
        if (!\in_array($structure, $this->structures, true)) {
            $this->structures[] = $structure;
        }
    }

    public function remove(VenueStructure $structure): void
    {
        $this->structures = array_values(array_filter($this->structures, static fn (VenueStructure $s) => $s !== $structure));
    }
}
