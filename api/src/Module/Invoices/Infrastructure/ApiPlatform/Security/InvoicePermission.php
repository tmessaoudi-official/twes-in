<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\Security;

/**
 * The permissions behind every invoices endpoint: reading invoices and credit notes, writing and cancelling drafts of
 * either kind, issuing one, which numbers it and fixes what it says, recording and deleting an invoice's payments, and, apart from
 * all of those, drafting and issuing a credit note.
 */
final class InvoicePermission
{
    public const string READ = 'invoice.read';
    /**
     * The home's money cards, what the business invoiced, collected, is owed and charged in VAT: their own, so a role made
     * to read invoices at the counter need not see the business's figures; every built-in role reading invoices holds it.
     */
    public const string SUMMARY_READ = 'invoice.summary.read';
    public const string WRITE = 'invoice.write';
    public const string ISSUE = 'invoice.issue';
    public const string PAYMENT_WRITE = 'payment.write';
    /** Drafting and issuing a credit note, which reverses revenue: a manager's, where issuing an invoice is a clerk's. */
    public const string CREDIT = 'invoice.credit';
}
