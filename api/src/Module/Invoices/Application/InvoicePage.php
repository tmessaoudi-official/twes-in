<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Module\Customers\Domain\CustomerSnapshot;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceFigures;

/**
 * What a printed invoice or credit note shows: the document, its figures, its customer, language, mentions and texts
 * as issuing kept them (as they stand today for a draft), and the printed notes the customer's settings give.
 */
final readonly class InvoicePage
{
    /** Printed across a document that is not yet one. */
    public const string DRAFT = 'draft';
    /** Printed across a draft that will never be one. */
    public const string CANCELLED = 'cancelled';

    /**
     * @param self::DRAFT|self::CANCELLED|null $watermark
     * @param string                           $language     fr or en
     * @param list<string>                     $mentionKeys  translation keys
     * @param string                           $dateFormat   the company's `presentation.date-format`, `auto` for the language's
     * @param string                           $numberFormat the company's `presentation.number-format`, `auto` for the language's
     */
    public function __construct(
        public Invoice $invoice,
        public InvoiceFigures $figures,
        public CustomerSnapshot $customer,
        public ?string $watermark,
        public string $language,
        public string $printedNotes,
        public array $mentionKeys,
        public ?string $latePenaltyText,
        public ?string $footer,
        public string $dateFormat = 'auto',
        public string $numberFormat = 'auto',
    ) {
    }
}
