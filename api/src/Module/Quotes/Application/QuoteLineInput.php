<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use Symfony\Component\Uid\Uuid;

/**
 * A line as it is written. What a line naming a product leaves out comes from that product: its name, its unit, its
 * price (the customer's list price where a price list sets one), and its default taxes the customer's regime charges;
 * a line without a product states all of them.
 */
final readonly class QuoteLineInput
{
    /**
     * @param list<Uuid>|null $taxComponentIds null for the product's default taxes; an empty list for none
     * @param string|null     $discountAmount  the line's whole discount as an amount, in place of a rate
     */
    public function __construct(
        public ?Uuid $productId,
        public ?string $description,
        public string $quantity,
        public ?Uuid $unitId = null,
        public ?string $unitPriceNet = null,
        public ?string $discountRate = null,
        public ?array $taxComponentIds = null,
        public ?string $discountAmount = null,
    ) {
    }
}
