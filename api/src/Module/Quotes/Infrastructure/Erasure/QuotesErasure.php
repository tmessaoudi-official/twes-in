<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Erasure;

use App\Erasure\Application\DeclaresErasure;
use App\Erasure\Application\ErasedRows;
use App\Erasure\Application\ErasureReference;
use App\Erasure\Application\NamedFile;
use App\Module\Quotes\Application\ManageQuotes;

/**
 * « Brouillons et devis », the quotes' share: a quote is never a fiscal document, so every one goes with its lines and its
 * attached files, but one an invoice that stays says it came from.
 */
final readonly class QuotesErasure implements DeclaresErasure
{
    public function steps(): array
    {
        $quotes = ErasedRows::of('drafts', 'quote', 'true', 'quotes', 'quote');
        $lines = $quotes->child('quote_line', 'quote_id');

        return [
            $quotes,
            $lines,
            $lines->child('quote_line_tax', 'line_id'),
            $quotes->child('attachment', 'entity_id', \sprintf("entity_type = '%s'", ManageQuotes::ENTITY_TYPE)),
        ];
    }

    public function references(): array
    {
        return [
            ErasureReference::link('quote', 'invoice_id', 'invoice', 'A quote that stays no longer names the draft invoice made from it, until the undo names it again.'),
        ];
    }

    public function files(): array
    {
        return [new NamedFile('attachment', 'file_id')];
    }
}
