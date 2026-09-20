<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * What a movements list asks for (docs/SPEC.md § 7, lists at scale, row 55 (b)): the words, the product, the location,
 * the kind and the source it narrows to, and the order. Unlike a stock level, a movement IS a row of a table — nothing
 * is grouped — so the database pages it straight and the total is a count, not an aggregate walked in PHP.
 *
 * Every one of these is here because the screen offers it. A list the API pages shows the page it was sent, so a
 * filter the API does not answer would quietly narrow that page alone and call it the result — which is the same
 * untruth as the cap this replaced, wearing a filter's clothes.
 */
final readonly class StockMovementSearch
{
    /**
     * Newest first is the only order a history is read in, so it is the default rather than a choice; the rest are
     * the columns the screen lets a person sort by.
     */
    public const array SORTS = ['movedAt', 'product', 'location', 'kind', 'quantity', 'source'];

    /** @param array<string, 'asc'|'desc'> $order one of SORTS per key, in the order it applies */
    public function __construct(
        public ?Uuid $product = null,
        public ?Uuid $location = null,
        public ?string $text = null,
        public ?StockMovementKind $kind = null,
        public ?string $sourceType = null,
        public array $order = [],
    ) {
    }
}
