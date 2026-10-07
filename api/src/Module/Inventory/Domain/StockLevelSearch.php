<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use App\Shared\Domain\DateRange;
use Symfony\Component\Uid\Uuid;

/**
 * What a stock list asks for (docs/SPEC.md § 7, lists at scale, and 2026-10-06 00:15): words found in the product's
 * reference or name or in the location's code or name, whatever their case and accents, then filters that combine —
 * the values of one OR'd (several locations, several products), different ones AND'd — and the order. A location stands
 * for every location under it and a product category for every category under it: the use case widens both.
 *
 * A row here is not a row of a table: it is the sum of everything that moved a product at a location, so the database
 * groups before it pages. That means the PAGE is bounded and the aggregate behind it is not — every movement of the
 * company is read to total it, however few rows come back. No trigram index applies for the same reason: the search
 * narrows rows the grouping already had to scan. If a company's movements ever outgrow that, what this needs is a
 * kept level per product and location, not a different query. Below zero is a condition on that sum, so it narrows
 * after the grouping.
 */
final readonly class StockLevelSearch
{
    public const array SORTS = ['reference', 'product', 'location', 'quantity'];

    /**
     * @param list<Uuid>                  $locations      any of them
     * @param list<Uuid>                  $establishments any of them
     * @param array<string, 'asc'|'desc'> $order          one of SORTS per key, in the order it applies
     * @param list<Uuid>                  $products       any of them
     * @param list<Uuid>                  $categories     any of the products' categories
     * @param bool|null                   $negative       true: what is on hand fell below zero; false: it did not; null: either
     * @param bool|null                   $expired        true: a lot past its use-by day and not released; false: anything else
     * @param DateRange|null              $lotExpiresOn   the lot's use-by day; a row without a lot or without a day is left out by any end
     * @param string|null                 $today          the company's day, which « past its use-by day » is read against
     */
    public function __construct(
        public ?string $text = null,
        public array $locations = [],
        public array $establishments = [],
        public array $order = [],
        public array $products = [],
        public array $categories = [],
        public ?bool $negative = null,
        public ?bool $expired = null,
        public ?DateRange $lotExpiresOn = null,
        public ?string $today = null,
    ) {
    }

    /**
     * The same search once the use case has widened its locations and categories to what sits under them.
     *
     * @param list<Uuid> $locations
     * @param list<Uuid> $categories
     */
    public function widened(array $locations, array $categories): self
    {
        return new self($this->text, $locations, $this->establishments, $this->order, $this->products, $categories, $this->negative, $this->expired, $this->lotExpiresOn, $this->today);
    }
}
