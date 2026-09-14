<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Module\DeliveryNotes\Domain\CustomerSnapshot;
use App\Module\DeliveryNotes\Domain\DeliveryNote;

/**
 * What a printed delivery note shows: the note, its figures, its customer as the note names it (as it was the day the
 * note was validated, as it is today for a draft), and what the customer's settings say about printing it.
 */
final readonly class DeliveryNotePage
{
    /** Printed across a note that is not yet a document. */
    public const string DRAFT = 'draft';
    /** Printed across a note that no longer is one. */
    public const string CANCELLED = 'cancelled';

    /**
     * @param self::DRAFT|self::CANCELLED|null $watermark
     * @param string                           $language  fr or en
     */
    public function __construct(
        public DeliveryNote $note,
        public DocumentTotals $totals,
        public CustomerSnapshot $customer,
        public ?string $watermark,
        public bool $showPrices,
        public string $language,
        public string $printedNotes,
    ) {
    }
}
