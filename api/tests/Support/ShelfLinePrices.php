<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\DeliveryNotes\Application\DeliveryNoteLinePrices;
use App\Module\Invoices\Application\InvoiceLinePrices;
use App\Module\Products\Domain\Product;
use Symfony\Component\Uid\Uuid;

/** No price list anywhere: a line sent without a price starts at its product's own. PriceListsTest runs the real one. */
final class ShelfLinePrices implements InvoiceLinePrices, DeliveryNoteLinePrices
{
    public function startingPrice(Product $product, Uuid $customerId, string $quantity): string
    {
        return $product->getDetails()->unitPriceNet;
    }
}
