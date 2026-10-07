<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

/**
 * What kind of document a person or an accountant sees in a list: the type, with the deposit told apart from the
 * invoice. A deposit is an invoice in every rule that numbers, issues and pays it, but its VAT fell due when it was
 * paid and the final invoice gives it back, so a file read for the return must not count it as a final invoice.
 */
enum InvoiceKind: string
{
    case Invoice = 'invoice';
    case Deposit = 'deposit';
    case CreditNote = 'credit_note';

    public static function of(Invoice $invoice): self
    {
        return match (true) {
            InvoiceType::CreditNote === $invoice->getType() => self::CreditNote,
            $invoice->isDeposit() => self::Deposit,
            default => self::Invoice,
        };
    }
}
