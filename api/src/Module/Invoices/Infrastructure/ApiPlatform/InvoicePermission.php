<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

/**
 * The permissions behind every invoices endpoint: reading invoices and credit notes, writing and cancelling drafts of
 * either kind, issuing one, which numbers it and fixes what it says, and recording and deleting an invoice's payments.
 */
final class InvoicePermission
{
    public const string READ = 'invoice.read';
    public const string WRITE = 'invoice.write';
    public const string ISSUE = 'invoice.issue';
    public const string PAYMENT_WRITE = 'payment.write';
}
