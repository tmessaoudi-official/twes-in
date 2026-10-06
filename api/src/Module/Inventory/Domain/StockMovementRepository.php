<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use App\Module\Products\Domain\Product;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

interface StockMovementRepository
{
    /**
     * Stores the movements together. A movement that came with no cost is valued here, at the weighted average of what
     * its product's stock was valued at (its cost price when none is), so no writer of stock can forget to value it.
     */
    public function save(StockMovement ...$movements): void;

    /**
     * What the stock of each product of the company is worth, those holding nothing left out.
     *
     * @return list<StockValue>
     */
    public function valuation(Uuid $companyId): array;

    /**
     * The quantity and the worth of the product's movements that carry a cost, signed: what its average is made of.
     *
     * @return array{quantity: numeric-string, amount: numeric-string}
     */
    public function valuedTotalsOf(Product $product): array;

    /**
     * The same totals over the product's movements before this one, under the product's lock: the stock a receipt
     * whose cost comes later met when it came in.
     *
     * @return array{quantity: numeric-string, amount: numeric-string}
     */
    public function valuedTotalsBefore(StockMovement $movement): array;

    /**
     * The product's movements that carry a cost after this one, in the order they were valued: what a late receipt cost
     * met once it came in.
     *
     * @return list<StockMovement>
     */
    public function valuedAfter(StockMovement $movement): array;

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?StockMovement;

    /** Writes a movement already valued as it now is, without valuing it again: a receipt whose cost came later. */
    public function saveValued(StockMovement $movement): void;

    /**
     * What credit notes have already returned of an invoice's sale.
     *
     * @return list<StockMovement>
     */
    public function ofReversing(Uuid $invoiceId, Uuid $companyId): array;

    /** The cost on the latest receipt of the product that came with one typed; none when no receipt did. */
    public function lastTypedCostOf(Product $product): ?TypedCost;

    /**
     * What one unit of the product is worth, the weighted average of the movements that carry a cost, or its cost price
     * while none does; null when it has neither.
     *
     * @return numeric-string|null four decimals
     */
    public function averageCostOf(Product $product): ?string;

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
     * The stock of a product that can be sold in one establishment: every location but those in quarantine, whose goods
     * wait for a decision. "0.000" when nothing ever moved there.
     *
     * @return numeric-string
     */
    public function sellableInEstablishment(Uuid $productId, Uuid $establishmentId): string;

    /**
     * The stock of a lot wherever it is in its company: how a serial number is known to be in stock once.
     *
     * @return numeric-string
     */
    public function onHandOfLot(Uuid $lotId): string;

    /** @return list<LotOnHand> each lot of the product something moved at the location, with its stock there */
    public function lotsAt(Uuid $productId, Uuid $locationId): array;

    /**
     * What is on hand of each of the products, every location and lot together; a product nothing moved for is "0.000".
     * Named, an establishment narrows it to the locations of that establishment.
     *
     * @param list<Uuid> $productIds
     *
     * @return array<string, numeric-string> by the product's id
     */
    public function totalsOf(Uuid $companyId, array $productIds, ?Uuid $establishmentId = null): array;

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
