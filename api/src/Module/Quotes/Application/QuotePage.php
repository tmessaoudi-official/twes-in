<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Module\Customers\Domain\CustomerSnapshot;
use App\Module\Quotes\Domain\Quote;
use App\Shared\Domain\DocumentDesign;
use App\Tenancy\Domain\SellerSnapshot;

/**
 * What a printed quote shows: the quote, its figures, its customer and seller as the quote names them (as they were
 * the day it was sent, as they are today for a draft), and what the customer's settings say about printing it.
 */
final readonly class QuotePage
{
    /** Printed across a quote that is not yet sent. */
    public const string DRAFT = 'draft';
    /** Printed across a draft that will not be sent. */
    public const string CANCELLED = 'cancelled';

    /**
     * @param self::DRAFT|self::CANCELLED|null $watermark
     * @param string                           $language  fr or en
     */
    public function __construct(
        public Quote $quote,
        public DocumentTotals $totals,
        public CustomerSnapshot $customer,
        public SellerSnapshot $seller,
        public ?string $watermark,
        public string $language,
        public string $printedNotes,
        /** Whether the quote ends with the « Bon pour accord » block: date, name, signature and cachet. */
        public bool $signatureBlock,
        /** The company's `presentation.date-format` and `presentation.number-format`, `auto` for the language's. */
        public string $dateFormat = 'auto',
        public string $numberFormat = 'auto',
        /** The layout and accent it prints in: as sending kept them, or the company's today for a draft. */
        public DocumentDesign $design = new DocumentDesign(),
        /** What the discounts take off, printed as « Vous économisez … »; null where the company keeps it off or nothing is taken off. */
        public ?string $savings = null,
    ) {
    }
}
