<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\ValueObject\VendorSnapshot;
use App\Shared\Domain\ValueObject\DocumentDesign;
use App\Tenancy\Domain\ValueObject\SellerSnapshot;

/**
 * What a printed order to a vendor shows: the order, what it comes to, the vendor and the buyer as the order names them
 * (as they were the day it was sent, as they are today for a draft), and how the company prints its documents.
 */
final readonly class PurchaseOrderPage
{
    /** Printed across an order that has not been sent. */
    public const string DRAFT = 'draft';
    /** Printed across an order that no longer stands. */
    public const string CANCELLED = 'cancelled';

    /**
     * @param self::DRAFT|self::CANCELLED|null $watermark
     * @param string                           $language   a language the catalogue prints documents in
     * @param list<QuantityTotal>              $quantities what the lines come to in each unit, empty for a single line
     */
    public function __construct(
        public PurchaseOrder $order,
        public PurchaseOrderTotals $totals,
        public VendorSnapshot $vendor,
        public SellerSnapshot $buyer,
        public ?string $watermark,
        public string $language,
        /** The company's `presentation.date-format` and `presentation.number-format`, `auto` for the language's. */
        public string $dateFormat = 'auto',
        public string $numberFormat = 'auto',
        public DocumentDesign $design = new DocumentDesign(),
        public array $quantities = [],
    ) {
    }
}
