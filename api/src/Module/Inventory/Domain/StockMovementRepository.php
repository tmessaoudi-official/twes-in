<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

interface StockMovementRepository
{
    /** Stores the movements together. */
    public function save(StockMovement ...$movements): void;

    /** @return list<StockMovement> what one document moved in a company, in the order it was written */
    public function ofSource(string $sourceType, Uuid $sourceId, Uuid $companyId): array;

    /** @return list<StockMovement> a company's latest movements, newest first */
    public function ofCompany(Uuid $companyId, int $limit): array;

    /** @return list<StockMovement> a product's latest movements in a company, newest first */
    public function ofProduct(Uuid $productId, Uuid $companyId, int $limit): array;

    /**
     * Holds the stock of a product at a location until the current transaction ends, so a count that reads it and a
     * delivery that takes from it run one after the other; a count would otherwise record its difference from a stock
     * the delivery had already changed. Taken inside a transaction only; callers taking several take them in a fixed order.
     */
    public function lockStockOf(Uuid $productId, Uuid $locationId): void;

    /**
     * The stock of a product at a location, "0.000" when nothing ever moved there.
     *
     * @return numeric-string
     */
    public function onHand(Uuid $productId, Uuid $locationId): string;

    /** @return list<StockLevel> every product and location of the company something moved in */
    public function levels(Uuid $companyId): array;

    /** How many movements a location has seen. */
    public function countAt(Uuid $locationId): int;
}
