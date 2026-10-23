<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Erasure;

use App\Erasure\Application\DeclaresErasure;
use App\Erasure\Application\ErasedRows;
use App\Erasure\Application\ErasureReference;

/** « Brouillons et devis », the delivery notes' share: a draft has moved no goods and been given no number, and goes whole. */
final readonly class DeliveryNoteDraftsErasure implements DeclaresErasure
{
    public function steps(): array
    {
        $drafts = ErasedRows::of('drafts', 'delivery_note', "status = 'draft'", 'drafts', 'delivery_note');
        $lines = $drafts->child('delivery_note_line', 'delivery_note_id');

        return [$drafts, $lines, $lines->child('delivery_note_line_tax', 'line_id')];
    }

    public function references(): array
    {
        return [
            ErasureReference::ignore('delivery_note', 'invoiced_by_invoice_id', 'invoice', 'Only an issued invoice marks a note invoiced.'),
            ErasureReference::ignore('delivery_note_line', 'source_invoice_line_id', 'invoice_line', 'Only an issued invoice is delivered by a note, and an issued invoice is kept.'),
            ErasureReference::link('delivery_note_line', 'source_quote_line_id', 'quote_line', 'A note that stays no longer names the quote line it delivered, until the undo names it again.', live: 'delivery_note'),
        ];
    }

    public function files(): array
    {
        return [];
    }
}
