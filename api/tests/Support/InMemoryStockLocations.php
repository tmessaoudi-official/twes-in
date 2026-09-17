<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryStockLocations implements StockLocationRepository
{
    /** @var list<StockLocation> */
    public array $locations = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->locations, static fn (StockLocation $l) => $l->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (StockLocation $a, StockLocation $b) => $a->getCode() <=> $b->getCode());

        return $mine;
    }

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids);

        return array_values(array_filter($this->ofCompany($companyId), static fn (StockLocation $l): bool => \in_array($l->getId()->toRfc4122(), $wanted, true)));
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?StockLocation
    {
        return array_find($this->ofCompany($companyId), static fn (StockLocation $l) => $l->getId()->equals($id));
    }

    public function defaultOf(Uuid $establishmentId): ?StockLocation
    {
        return array_find($this->locations, static fn (StockLocation $l) => $l->isDefault() && $l->getEstablishment()->getId()->equals($establishmentId));
    }

    public function ofCodeInEstablishment(string $code, Uuid $establishmentId): ?StockLocation
    {
        return array_find($this->locations, static fn (StockLocation $l) => $l->getCode() === $code && $l->getEstablishment()->getId()->equals($establishmentId));
    }

    public function countChildren(Uuid $locationId): int
    {
        return \count(array_filter($this->locations, static fn (StockLocation $l) => true === $l->getParent()?->getId()->equals($locationId)));
    }

    public function save(StockLocation $location): void
    {
        if (!\in_array($location, $this->locations, true)) {
            $this->locations[] = $location;
        }
    }

    public function remove(StockLocation $location): void
    {
        $this->locations = array_values(array_filter($this->locations, static fn (StockLocation $l) => $l !== $location));
    }
}
