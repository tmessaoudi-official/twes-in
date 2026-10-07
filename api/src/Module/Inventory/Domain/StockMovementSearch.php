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
 * What a movements list asks for (docs/SPEC.md § 7, lists at scale, row 55 (b), and 2026-10-06 00:15): the words, then
 * filters that combine — the values of one OR'd (several kinds, several locations), different ones AND'd — the lot, the
 * day the goods moved on in the company's own calendar, and the order. Unlike a stock level, a movement IS a row of a
 * table — nothing is grouped — so the database pages it straight and the total is a count, not an aggregate walked in PHP.
 *
 * Every one of these is here because the screen offers it. A list the API pages shows the page it was sent, so a
 * filter the API does not answer would quietly narrow that page alone and call it the result — which is the same
 * untruth as the cap this replaced, wearing a filter's clothes. A location stands for itself and every location under
 * it: the use case widens it before the search reaches the repository.
 */
final readonly class StockMovementSearch
{
    /**
     * Newest first is the only order a history is read in, so it is the default rather than a choice; the rest are
     * the columns the screen lets a person sort by.
     */
    public const array SORTS = ['movedAt', 'product', 'location', 'kind', 'quantity', 'source'];

    /**
     * @param list<Uuid>                  $products       any of them
     * @param list<Uuid>                  $locations      any of them
     * @param list<StockMovementKind>     $kinds          any of them
     * @param list<string>                $sourceTypes    any of them, as `StockMovement::SOURCE_*` names them
     * @param array<string, 'asc'|'desc'> $order          one of SORTS per key, in the order it applies
     * @param list<StockLossReason>       $reasons        any of them; a movement that is not a loss has none and is left out by any
     * @param bool|null                   $costToComplete true: a receipt whose cost waits to be entered; false: anything else; null: either
     * @param string                      $timezone       the company's, which the days of `movedOn` are counted in
     */
    public function __construct(
        public array $products = [],
        public array $locations = [],
        public ?string $text = null,
        public array $kinds = [],
        public array $sourceTypes = [],
        public array $order = [],
        /** A lot or serial code, matched whole and whatever its case: where a recall starts (row 63 slice 10). */
        public ?string $lot = null,
        public array $reasons = [],
        public ?bool $costToComplete = null,
        public ?DateRange $movedOn = null,
        public string $timezone = 'UTC',
    ) {
    }

    /**
     * The same search under other locations: what the use case hands the repository once it has widened them.
     *
     * @param list<Uuid> $locations
     */
    public function withLocations(array $locations): self
    {
        return new self($this->products, $locations, $this->text, $this->kinds, $this->sourceTypes, $this->order, $this->lot, $this->reasons, $this->costToComplete, $this->movedOn, $this->timezone);
    }
}
