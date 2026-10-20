<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Records;

use App\Module\Quotes\Domain\ValueObject\QuoteStatus;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;

/** A quote names the customer it is made to and its lines the products they offer: what a quote names is used. */
final readonly class QuoteRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('quote_line', 'product_id', 'product', RecordMerge::Moves, 'A quote line names the product it offers; merged, a draft offers the product kept, a sent quote what it printed.')
                ->through('quote', 'quote_id')
                ->movingOnlyWhere('status', [QuoteStatus::Draft->value]),
            RecordReference::uses('quote', 'customer_id', 'customer', RecordMerge::Moves, 'A quote is made to its customer; merged, a draft is made to the customer kept, a sent quote to whom it printed.')
                ->movingOnlyWhere('status', [QuoteStatus::Draft->value])
                ->transactingAt('updated_at'),
        ];
    }
}
