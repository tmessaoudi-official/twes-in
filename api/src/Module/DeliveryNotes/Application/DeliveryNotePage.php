<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Fiscal\Domain\ValueObject\PrintedNames;
use App\Module\Customers\Domain\ValueObject\CustomerSnapshot;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Shared\Domain\ValueObject\DocumentDesign;
use App\Tenancy\Domain\ValueObject\SellerSnapshot;

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
    /** Printed across a delivered note printed again: the original stays the one validation stored. */
    public const string COPY = 'copy';

    /**
     * @param self::DRAFT|self::CANCELLED|self::COPY|null $watermark
     * @param string                                      $language   a language the catalogue prints documents in
     * @param list<QuantityTotal>                         $quantities what the lines come to in each unit, empty for a single line
     * @param PrintedNames                                $names      what it calls its units and each of the company's taxes, by code; a tax code it lacks prints as itself
     */
    public function __construct(
        public DeliveryNote $note,
        public DocumentTotals $totals,
        public CustomerSnapshot $customer,
        public SellerSnapshot $seller,
        public ?string $watermark,
        public bool $showPrices,
        public string $language,
        public string $printedNotes,
        /** Whether the note ends with its reception block: date and time, name, signature and cachet, réserves. */
        public bool $receptionBlock,
        /** The company's `presentation.date-format` and `presentation.number-format`, `auto` for the language's. */
        public string $dateFormat = 'auto',
        public string $numberFormat = 'auto',
        /** The layout and accent it prints in: as validation kept them, or the company's today for a draft. */
        public DocumentDesign $design = new DocumentDesign(),
        public array $quantities = [],
        /** What fills the customer's exemption mention, `%reference%`; null leaves a mention that needs it unprinted. */
        public ?string $exemptionReference = null,
        public PrintedNames $names = new PrintedNames([], []),
        /** The number of the accepted quote the note delivers, printed as its reference; null for none. */
        public ?string $quoteNumber = null,
        /** The number of the issued invoice whose goods the note hands over, printed as its reference; null for none. */
        public ?string $invoiceNumber = null,
        /** Who received the goods, in the reception block's « Nom » or on a line of its own; null for nobody named. */
        public ?string $receiverName = null,
        /** A delivered copy, which says when the goods arrived in the reception block and on its face. */
        public bool $deliveredCopy = false,
    ) {
    }
}
