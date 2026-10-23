<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use Symfony\Component\Uid\Uuid;

/**
 * A line as it is written. What a line naming a product leaves out comes from that product: its name, its unit, its
 * price, and its default taxes the customer's regime charges; a line without a product states all of them.
 */
final readonly class DeliveryNoteLineInput
{
    /**
     * @param list<Uuid>|null $taxComponentIds     null for the product's default taxes; an empty list for none
     * @param string|null     $lotCode             the lot or serial handed over, for a product tracked by one
     * @param Uuid|null       $sourceQuoteLineId   the line of an accepted quote it delivers: what it leaves out comes from
     *                                             that line, and what it states must be what that line says
     * @param Uuid|null       $sourceInvoiceLineId the line of an issued invoice whose goods it hands over, kept the same way
     */
    public function __construct(
        public ?Uuid $productId,
        public ?string $description,
        public string $quantity,
        public ?Uuid $unitId = null,
        public ?string $unitPriceNet = null,
        public ?array $taxComponentIds = null,
        public ?string $lotCode = null,
        public ?Uuid $sourceQuoteLineId = null,
        public ?Uuid $sourceInvoiceLineId = null,
    ) {
    }
}
