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

    public function defaultOf(Uuid $establishmentId): ?StockLocation;

    public function ofCodeInEstablishment(string $code, Uuid $establishmentId): ?StockLocation;

    /** How many locations sit directly under this one. */
    public function countChildren(Uuid $locationId): int;

    public function save(StockLocation $location): void;

    public function remove(StockLocation $location): void;
}
