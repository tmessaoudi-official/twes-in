<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

final readonly class LineInput
{
    /**
     * @param string         $unitPrice    net of tax or tax-inclusive, as the document's basis says; never negative
     * @param string|null    $discountRate a percentage, as typed; it reduces the tax base
     * @param list<TaxInput> $taxes        the line's percentage taxes, in the order they are printed
     */
    public function __construct(
        public string $quantity,
        public string $unitPrice,
        public ?string $discountRate = null,
        public array $taxes = [],
    ) {
    }
}
