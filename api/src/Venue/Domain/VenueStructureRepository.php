<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Domain;

use Symfony\Component\Uid\Uuid;

interface VenueStructureRepository
{
    /** @return list<VenueStructure> the building drawn on one floor, oldest first */
    public function ofArea(Uuid $areaId): array;

    /** Null for a piece that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?VenueStructure;

    public function save(VenueStructure $structure): void;

    public function remove(VenueStructure $structure): void;
}
