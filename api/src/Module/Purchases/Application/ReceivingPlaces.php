<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Where an order's goods may be put away, asked of the stock: the purchases module names no stock class, so whoever
 * may receive is told the places without needing the right to read the stock.
 */
interface ReceivingPlaces
{
    /**
     * The establishment's places, by code; each names the one it sits under, so a screen can lay out their paths.
     *
     * @return list<ReceivingPlace>
     */
    public function placesOf(Company $company, Uuid $establishmentId): array;

    /**
     * Where each of these products lives in the establishment, by product id: its first home there. A product with no
     * home there is left out.
     *
     * @param list<Uuid> $productIds
     *
     * @return array<string, Uuid>
     */
    public function homesOf(Company $company, Uuid $establishmentId, array $productIds): array;
}
