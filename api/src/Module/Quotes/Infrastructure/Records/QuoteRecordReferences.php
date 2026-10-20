<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Records;

use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Domain\ValueObject\QuoteStatus;
use App\Module\Quotes\Infrastructure\ApiPlatform\Security\QuotePermission;
use App\Module\Quotes\Infrastructure\Module\QuotesModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/** A quote names the customer it is made to and its lines the products they offer: what a quote names is used. */
final readonly class QuoteRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        // Only a draft is deleted: a sent quote is what the customer holds. A draft has no number yet, so it reads as
        // the customer it is made to.
        return [new RecordSubject('quote', QuotePermission::WRITE, QuotesModule::KEY, ['number'], whereColumn: 'status', whereValues: [QuoteStatus::Draft->value], labelTable: 'customer', labelKey: 'customer_id', labelTableColumns: ['number', 'name'])];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('quote', 'invoice_id', 'invoice', RecordMerge::Ignored, 'A quote names the invoice it went into; that invoice is kept.'),
            RecordReference::owned('quote_line', 'quote_id', 'quote', RecordMerge::Ignored, 'A line is part of its draft: it goes with it and comes back with it.'),
            RecordReference::owned('quote_line_tax', 'line_id', 'quote', RecordMerge::Ignored, 'A line\'s tax is part of its line: it goes before it and comes back after it.')
                ->below('quote_line', 'quote_id'),
            RecordReference::owned('attachment', 'entity_id', 'quote', RecordMerge::Ignored, 'A file attached to a draft goes with it, and is let go with it once the delete is final.')
                ->where('entity_type', ManageQuotes::ENTITY_TYPE),
            RecordReference::uses('quote_line', 'product_id', 'product', RecordMerge::Moves, 'A quote line names the product it offers; merged, a draft offers the product kept, a sent quote what it printed.')
                ->through('quote', 'quote_id')
                ->movingOnlyWhere('status', [QuoteStatus::Draft->value]),
            RecordReference::uses('quote', 'customer_id', 'customer', RecordMerge::Moves, 'A quote is made to its customer; merged, a draft is made to the customer kept, a sent quote to whom it printed.')
                ->movingOnlyWhere('status', [QuoteStatus::Draft->value])
                ->transactingAt('updated_at'),
        ];
    }
}
