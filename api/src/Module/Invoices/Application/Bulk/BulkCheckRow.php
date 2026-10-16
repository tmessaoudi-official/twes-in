<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Bulk;

use App\Module\Invoices\Domain\Model\Invoice;
use Symfony\Component\Uid\Uuid;

/**
 * One selected id and what a bulk action would do with it: the invoice goes in, or it is skipped for a reason a screen
 * translates. An id that is no invoice of the company has no invoice to show.
 */
final readonly class BulkCheckRow
{
    public const string NOT_FOUND = 'not_found';
    /** Not numbered yet: printed only when asked for, under its watermark. */
    public const string DRAFT = 'draft';
    /** A draft that will never be numbered. */
    public const string CANCELLED = 'cancelled';
    /** Nothing is left to pay on it. */
    public const string PAID = 'paid';
    /** Owed by the company, never paid to it. */
    public const string CREDIT_NOTE = 'credit_note';
    /** A cheque or traite still out on it: paying it now would count the money twice once the instrument is cashed. */
    public const string INSTRUMENT_HELD = 'instrument_held';
    /** Issued after the day the payment is dated. */
    public const string ISSUED_AFTER = 'issued_after';

    /** @param ?string $amount what a payment would record on it, with three decimals, when it is paid */
    public function __construct(public Uuid $id, public ?Invoice $invoice, public ?string $skipped = null, public ?string $amount = null)
    {
    }

    public function included(): bool
    {
        return null === $this->skipped;
    }
}
