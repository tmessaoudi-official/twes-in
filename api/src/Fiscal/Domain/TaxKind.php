<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain;

/**
 * The closed set of tax arithmetic the calculator implements once (docs/SPEC.md § 3 Fiscal presets). A new
 * country is data; a new kind is code, and a ruling first.
 */
enum TaxKind: string
{
    /** A rate on each line's net: VAT, and a levy such as Tunisia's FODEC. */
    case PercentageLine = 'percentage_line';
    /** One amount per document, outside every base and every discount: Tunisia's stamp duty. */
    case FixedDocument = 'fixed_document';
    /** A rate on the document total from a threshold on, shown on the invoice and deducted from the amount due. */
    case WithholdingTotal = 'withholding_total';
}
