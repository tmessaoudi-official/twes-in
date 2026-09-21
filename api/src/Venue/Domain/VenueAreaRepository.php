<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Domain;

use Symfony\Component\Uid\Uuid;

interface VenueAreaRepository
{
    /** @return list<VenueArea> one company's areas, by establishment then level */
    public function ofCompany(Uuid $companyId): array;

    /** Null for an area that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?VenueArea;

    /** The area an establishment already draws at this level, so two floors cannot share one. */
    public function ofLevelInEstablishment(int $level, Uuid $establishmentId): ?VenueArea;

    public function save(VenueArea $area): void;

    public function remove(VenueArea $area): void;
}
