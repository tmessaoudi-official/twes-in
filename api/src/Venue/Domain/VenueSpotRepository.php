<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Domain;

use Symfony\Component\Uid\Uuid;

interface VenueSpotRepository
{
    /** @return list<VenueSpot> everything drawn on one area, oldest first */
    public function ofArea(Uuid $areaId): array;

    /** Null for a spot that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?VenueSpot;

    /**
     * @param list<Uuid> $ids
     *
     * @return list<VenueSpot> those of the company among these ids, in no particular order
     */
    public function ofIdsInCompany(array $ids, Uuid $companyId): array;

    public function save(VenueSpot $spot): void;

    public function remove(VenueSpot $spot): void;
}
