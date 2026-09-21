<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Venue\Domain\VenueArea;
use App\Venue\Domain\VenueAreaRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryVenueAreas implements VenueAreaRepository
{
    /** @var list<VenueArea> */
    public array $areas = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->areas, static fn (VenueArea $a) => $a->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (VenueArea $a, VenueArea $b) => [$a->getEstablishment()->getCode(), $a->getLevel()] <=> [$b->getEstablishment()->getCode(), $b->getLevel()]);

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?VenueArea
    {
        return array_find($this->ofCompany($companyId), static fn (VenueArea $a) => $a->getId()->equals($id));
    }

    public function ofLevelInEstablishment(int $level, Uuid $establishmentId): ?VenueArea
    {
        return array_find($this->areas, static fn (VenueArea $a) => $a->getLevel() === $level && $a->getEstablishment()->getId()->equals($establishmentId));
    }

    public function save(VenueArea $area): void
    {
        if (!\in_array($area, $this->areas, true)) {
            $this->areas[] = $area;
        }
    }

    public function remove(VenueArea $area): void
    {
        $this->areas = array_values(array_filter($this->areas, static fn (VenueArea $a) => $a !== $area));
    }
}
