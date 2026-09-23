<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

interface StockMovementRepository
{
    /** Stores the movements together. */
    public function save(StockMovement ...$movements): void;

    /** @return list<StockMovement> what one document moved in a company, in the order it was written */
    public function ofSource(string $sourceType, Uuid $sourceId, Uuid $companyId): array;

    /**
     * Holds the stock of a product at a location until the current transaction ends, so a count that reads it and a
     * delivery that takes from it run one after the other; a count would otherwise record its difference from a stock
     * the delivery had already changed. Taken inside a transaction only; callers taking several take them in a fixed order.
     */
    public function lockStockOf(Uuid $productId, Uuid $locationId): void;

    /**
     * The stock of a product at a location, "0.000" when nothing ever moved there; of one of its lots when one is named.
     *
     * @return numeric-string
     */
    public function onHand(Uuid $productId, Uuid $locationId, ?Uuid $lotId = null): string;

    /**
     * The stock of a lot wherever it is in its company: how a serial number is known to be in stock once.
     *
     * @return numeric-string
     */
    public function onHandOfLot(Uuid $lotId): string;

    /** @return list<LotOnHand> each lot of the product something moved at the location, with its stock there */
    public function lotsAt(Uuid $productId, Uuid $locationId): array;

    /** @return list<StockLevel> every product, location and lot of the company something moved in */
    public function levels(Uuid $companyId): array;

    /**
     * One page of the same, searched, narrowed and sorted by the database. The page is bounded; the grouping behind
     * it is not — see StockLevelSearch.
     *
     * @return Page<StockLevel>
     */
    public function searchLevels(Uuid $companyId, StockLevelSearch $search, PageRequest $page): Page;

    /**
     * One page of a company's movements, newest first, narrowed to a product or a location.
     *
     * Rows of the table itself, so unlike searchLevels the total is a count and the page is bounded end to end.
     *
     * @return Page<StockMovement>
     */
    public function searchMovements(Uuid $companyId, StockMovementSearch $search, PageRequest $page): Page;

    /** How many movements a location has seen. */
    public function countAt(Uuid $locationId): int;

    /** How many movements a product has seen at a location: none means its stock there was never opened. */
    public function countOf(Uuid $productId, Uuid $locationId): int;
}
