<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use Symfony\Component\Uid\Uuid;

/**
 * One selected id and whether invoicing the selection would take it: the note goes onto the invoice, or it is skipped
 * for a reason a screen translates. An id that is no note of the company has no note to show.
 */
final readonly class InvoicingCheckRow
{
    public const string NOT_FOUND = 'not_found';
    /** Not validated yet: nothing was handed over to invoice. */
    public const string DRAFT = 'draft';
    public const string CANCELLED = 'cancelled';
    /** Its invoice is issued. */
    public const string INVOICED = 'invoiced';
    /** Every line is on an invoice that is not cancelled, a draft included. */
    public const string NOTHING_LEFT = 'nothing_left';
    /** Of another customer than the invoice's: one invoice is addressed to one customer. */
    public const string OTHER_CUSTOMER = 'other_customer';
    /** Of another establishment than the invoice's, whose series numbers it. */
    public const string OTHER_ESTABLISHMENT = 'other_establishment';

    public function __construct(public Uuid $id, public ?DeliveryNote $note, public ?string $skipped = null)
    {
    }

    public function included(): bool
    {
        return null === $this->skipped;
    }
}
