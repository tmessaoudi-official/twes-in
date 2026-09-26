<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/** One VAT category and rate of the document (BG-23): its taxable amount (BT-116) and its tax (BT-117). */
final readonly class CiiVatBreakdown
{
    public function __construct(public CiiVat $vat, public string $basis, public string $tax)
    {
    }
}
