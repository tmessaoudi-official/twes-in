<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;

/** A quote's line names the product it offers: a product a quote names is used. */
final readonly class QuoteRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('quote_line', 'product_id', 'product', RecordMerge::Stays, 'A quote line names the product it offers.'),
        ];
    }
}
