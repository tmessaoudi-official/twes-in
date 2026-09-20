<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

interface StockLocationRepository
{
    /** @return list<StockLocation> one company's locations, by code */
    public function ofCompany(Uuid $companyId): array;

    /** Null for a location that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?StockLocation;

    /**
     * @param list<Uuid> $ids
     *
     * @return list<StockLocation> those of the company among these ids, in no particular order
     */
    public function ofIdsInCompany(array $ids, Uuid $companyId): array;

    public function defaultOf(Uuid $establishmentId): ?StockLocation;

    public function ofCodeInEstablishment(string $code, Uuid $establishmentId): ?StockLocation;

    /**
     * The company's locations under one code. A code names one place per establishment, so this answers several only
     * when two establishments use the same one — which a file naming a code alone cannot tell apart.
     *
     * @return list<StockLocation>
     */
    public function ofCodeInCompany(string $code, Uuid $companyId): array;

    /** How many locations sit directly under this one. */
    public function countChildren(Uuid $locationId): int;

    public function save(StockLocation $location): void;

    public function remove(StockLocation $location): void;
}
