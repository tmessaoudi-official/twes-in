<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

/** Where an invoice or a credit note stands (docs/SPEC.md § 4 invoice); a credit note is never paid. */
enum InvoiceStatus: string
{
    /** Still being written: no number, every field revisable. */
    case Draft = 'draft';
    /** Numbered and fixed, nothing paid or credited yet. */
    case Issued = 'issued';
    /** Something paid or credited, something still due. */
    case PartiallyPaid = 'partially_paid';
    /** Nothing due. */
    case Paid = 'paid';
    /** A draft withdrawn; an issued document is corrected by a credit note instead. */
    case Cancelled = 'cancelled';
}
