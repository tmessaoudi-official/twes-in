<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application\Delivery;

/** What a quote's notes that are not cancelled deliver: the notes, the oldest first, and per line what they take. */
final readonly class QuoteDeliveries
{
    /**
     * @param list<QuoteDeliveryNote> $notes
     * @param array<string, string>   $onNotes   what the notes take of each line, drafts included, by quote line id
     * @param array<string, string>   $delivered what the notes past their draft took, by quote line id
     */
    public function __construct(public array $notes, public array $onNotes, public array $delivered)
    {
    }
}
