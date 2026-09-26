<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/**
 * A VAT category and rate as EN 16931 names them on a line, a document allowance or a breakdown (BT-151/152, BT-95/96,
 * BT-118/119/121): `S` at a rate, or a category under which no VAT is charged at 0 with the VATEX code of its exemption.
 */
final readonly class CiiVat
{
    /**
     * @param string      $category      UNTDID 5305: S, E, AE, K, G, O
     * @param string|null $rate          a percentage with at least two decimals; null for O, which carries none
     * @param string|null $exemptionCode a CEF VATEX code; null for S
     */
    public function __construct(public string $category, public ?string $rate, public ?string $exemptionCode)
    {
    }
}
