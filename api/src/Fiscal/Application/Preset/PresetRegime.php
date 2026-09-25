<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

use App\Fiscal\Domain\TaxFamily;

/**
 * A tax regime a customer (or a company) is under: which tax families it removes, and what the invoice must say. A
 * regime that removes VAT may also say what that means in an EN 16931 invoice: the VAT category of its lines (BT-151,
 * BT-118) and the VATEX code of the exemption (BT-121), both or neither; a regime that names no article declares neither.
 */
final readonly class PresetRegime
{
    /** The EN 16931 VAT categories (UNTDID 5305) under which no VAT is charged: exempt, reverse charge, intra-EU, export, outside the scope. */
    public const array VAT_CATEGORIES_WITHOUT_VAT = ['E', 'AE', 'K', 'G', 'O'];

    /** @param list<TaxFamily> $excludedFamilies */
    public function __construct(
        public string $code,
        public string $labelKey,
        public array $excludedFamilies,
        public ?string $mentionKey,
        public int $sortOrder,
        public ?string $vatCategory = null,
        public ?string $vatExemptionCode = null,
    ) {
    }
}
