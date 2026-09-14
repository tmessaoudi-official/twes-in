<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use Symfony\Component\Uid\Uuid;

/**
 * A line as it is written. What a line naming a product leaves out comes from that product: its name, its unit, its
 * price, and its default taxes the customer's regime charges; a line without a product states all of them. A discount
 * left out is no discount. A line of a draft drafted from delivery notes names the delivery note line it invoices.
 */
final readonly class InvoiceLineInput
{
    /**
     * @param list<Uuid>|null $taxComponentIds          null for the product's default taxes; an empty list for none
     * @param Uuid|null       $sourceDeliveryNoteLineId only one its draft already invoices
     */
    public function __construct(
        public ?Uuid $productId,
        public ?string $description,
        public string $quantity,
        public ?Uuid $unitId = null,
        public ?string $unitPriceNet = null,
        public ?string $discountRate = null,
        public ?array $taxComponentIds = null,
        public ?Uuid $sourceDeliveryNoteLineId = null,
    ) {
    }
}
