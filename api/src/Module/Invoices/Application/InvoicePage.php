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
use App\Tenancy\Domain\SellerSnapshot;

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
    /** Printed across a duplicate of an issued document, and across its up-to-date copy. */
    public const string DUPLICATE = 'duplicate';
    public const string COPY = 'copy';

    /**
     * @param self::DRAFT|self::CANCELLED|self::DUPLICATE|self::COPY|null $watermark
     * @param string                                                      $language     fr or en
     * @param list<string>                                                $mentionKeys  translation keys
     * @param string                                                      $dateFormat   the company's `presentation.date-format`, `auto` for the language's
     * @param string                                                      $numberFormat the company's `presentation.number-format`, `auto` for the language's
     */
    public function __construct(
        public Invoice $invoice,
        public InvoiceFigures $figures,
        public CustomerSnapshot $customer,
        public SellerSnapshot $seller,
        public ?string $watermark,
        public string $language,
        public string $printedNotes,
        public array $mentionKeys,
        public ?string $latePenaltyText,
        public ?string $footer,
        public string $dateFormat = 'auto',
        public string $numberFormat = 'auto',
        /** The total written out, null where the document does not carry it or there are no words for its currency or language. */
        public ?string $amountInWords = null,
        /** Whether the seller's IBAN and BIC are printed as the way to pay, the invoice's number being the reference. */
        public bool $howToPay = false,
        /** Set on a copy of an issued document, null on the original. */
        public ?InvoiceCopy $copy = null,
        /** The day the copy was printed, in the company's time zone. */
        public ?\DateTimeImmutable $copiedOn = null,
        /** What an up-to-date copy shows of the payments: `partial` or `paid`; null on every other output. */
        public ?string $paidStamp = null,
    ) {
    }
}
