<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Venue\Domain\VenueSpot;
use App\Venue\Domain\VenueSpotRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryVenueSpots implements VenueSpotRepository
{
    /** @var list<VenueSpot> */
    public array $spots = [];

    public function ofArea(Uuid $areaId): array
    {
        return array_values(array_filter($this->spots, static fn (VenueSpot $s) => $s->getArea()->getId()->equals($areaId)));
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?VenueSpot
    {
        return array_find($this->spots, static fn (VenueSpot $s) => $s->getId()->equals($id) && $s->getCompany()->getId()->equals($companyId));
    }

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids);

        return array_values(array_filter(
            $this->spots,
            static fn (VenueSpot $s): bool => $s->getCompany()->getId()->equals($companyId) && \in_array($s->getId()->toRfc4122(), $wanted, true),
        ));
    }

    public function save(VenueSpot $spot): void
    {
        if (!\in_array($spot, $this->spots, true)) {
            $this->spots[] = $spot;
        }
    }

    public function remove(VenueSpot $spot): void
    {
        $this->spots = array_values(array_filter($this->spots, static fn (VenueSpot $s) => $s !== $spot));
    }
}
