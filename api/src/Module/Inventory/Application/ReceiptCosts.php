<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\Product;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * What a receipt does to what a product costs the company, when the company has its receipts move the cost. A port
 * this module owns, answered by the products', so neither calls into the other (docs/SPEC.md § 7, audit 2026-10-06 C-4).
 * It runs inside the receipt's transaction, so the cost and its history commit with the goods.
 */
interface ReceiptCosts
{
    /** @throws InvalidProduct when the products refuse the cost */
    public function received(Company $company, Product $product, string $cost, Uuid $receiptId, ?Uuid $actorUserId): void;
}
