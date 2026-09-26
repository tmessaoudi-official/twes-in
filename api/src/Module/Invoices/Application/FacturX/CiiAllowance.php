<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/** The document's discount on one VAT category and rate (BG-20: BT-92 under BT-95 and BT-96), a discount (reason code 95). */
final readonly class CiiAllowance
{
    public function __construct(public string $amount, public CiiVat $vat)
    {
    }
}
