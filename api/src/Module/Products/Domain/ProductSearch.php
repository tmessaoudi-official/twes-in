<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/**
 * What a products list asks for (docs/SPEC.md § 7, lists at scale): words found in the reference, name or barcode,
 * whatever their case and accents (under three characters, the reference only), the kind, whether they are active,
 * and the order, always ending on the reference so a page never shifts.
 */
final readonly class ProductSearch
{
    public const array SORTS = ['reference', 'name', 'kind', 'category', 'isActive'];

    /** @param array<string, 'asc'|'desc'> $order one of SORTS per key, in the order it applies */
    public function __construct(
        public ?string $text = null,
        public ?ProductKind $kind = null,
        public ?bool $active = null,
        public array $order = [],
    ) {
    }
}
