<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Delivery;

/** What an invoice's notes that are not cancelled deliver: the notes, the oldest first, and per line what they take. */
final readonly class InvoiceDeliveries
{
    /**
     * @param list<InvoiceDeliveryNote> $notes
     * @param array<string, string>     $onNotes   what the notes take of each line, drafts included, by invoice line id
     * @param array<string, string>     $delivered what the notes past their draft took, by invoice line id
     */
    public function __construct(public array $notes, public array $onNotes, public array $delivered)
    {
    }
}
