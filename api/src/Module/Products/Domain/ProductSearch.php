<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use App\Shared\Domain\DecimalRange;
use Symfony\Component\Uid\Uuid;

/**
 * What a products list asks for (docs/SPEC.md § 7, lists at scale, and 2026-10-06 00:15): words found in the reference,
 * name or barcode, whatever their case and accents (under three characters, the reference only), then filters that
 * combine — the values of one OR'd (several kinds, several ways of tracking, several categories), different ones AND'd —
 * and the order, always ending on the reference so a page never shifts. A category stands for every category under it:
 * the use case widens it.
 */
final readonly class ProductSearch
{
    public const array SORTS = ['reference', 'name', 'kind', 'category', 'isActive'];

    /**
     * @param list<ProductKind>           $kinds        any of them
     * @param array<string, 'asc'|'desc'> $order        one of SORTS per key, in the order it applies
     * @param list<ProductTracking>       $trackings    any of them
     * @param list<Uuid>                  $categories   any of them
     * @param DecimalRange|null           $unitPriceNet the selling price before tax, each end inclusive
     */
    public function __construct(
        public ?string $text = null,
        public array $kinds = [],
        public ?bool $active = null,
        public array $order = [],
        public array $trackings = [],
        public array $categories = [],
        public ?DecimalRange $unitPriceNet = null,
    ) {
    }

    /**
     * The same search once the use case has widened its categories to what sits under them.
     *
     * @param list<Uuid> $categories
     */
    public function withCategories(array $categories): self
    {
        return new self($this->text, $this->kinds, $this->active, $this->order, $this->trackings, $categories, $this->unitPriceNet);
    }
}
