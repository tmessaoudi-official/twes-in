<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Module\Products\Domain\Product;
use Symfony\Component\Uid\Uuid;

/**
 * The net unit price a product line of a delivery note starts at when it is sent without one: the customer's list price for its
 * quantity where a price list sets one, else the product's own (docs/SPEC.md § 7, audit 2026-10-06 A-5). A port this
 * module owns, answered by the price lists', so neither calls into the other (§ 7, audit C-4).
 */
interface DeliveryNoteLinePrices
{
    /** @return string the price, net of tax, as a list row or the product keeps it */
    public function startingPrice(Product $product, Uuid $customerId, string $quantity): string;
}
