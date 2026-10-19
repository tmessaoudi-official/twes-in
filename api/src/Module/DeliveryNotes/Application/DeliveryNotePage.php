<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\QuantityTotal;
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

    /**
     * @param self::DRAFT|self::CANCELLED|null $watermark
     * @param string                           $language   a language the catalogue prints documents in
     * @param list<QuantityTotal>              $quantities what the lines come to in each unit, empty for a single line
     * @param array<string, string>            $taxNames   each of the company's taxes named in the document's language, by code
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
        public array $taxNames = [],
    ) {
    }
}
