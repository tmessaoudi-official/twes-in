<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * What a stock list asks for (docs/SPEC.md § 7, lists at scale): words found in the product's reference or name or in
 * the location's code or name, whatever their case and accents, one location, one establishment, and the order.
 *
 * A row here is not a row of a table: it is the sum of everything that moved a product at a location, so the database
 * groups before it pages. That means the PAGE is bounded and the aggregate behind it is not — every movement of the
 * company is read to total it, however few rows come back. No trigram index applies for the same reason: the search
 * narrows rows the grouping already had to scan. If a company's movements ever outgrow that, what this needs is a
 * kept level per product and location, not a different query.
 */
final readonly class StockLevelSearch
{
    public const array SORTS = ['reference', 'product', 'location', 'quantity'];

    /** @param array<string, 'asc'|'desc'> $order one of SORTS per key, in the order it applies */
    public function __construct(
        public ?string $text = null,
        public ?Uuid $location = null,
        public ?Uuid $establishment = null,
        public array $order = [],
    ) {
    }
}
