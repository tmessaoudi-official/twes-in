<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Inventory;

use App\Module\Inventory\Application\ReceiptCosts;
use App\Module\Products\Application\ChangeProductCost;
use App\Module\Products\Domain\CostChangeSource;
use App\Module\Products\Domain\Product;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/** Answers the inventory's `ReceiptCosts` port: a receipt changes the cost as this module changes any cost, with its history. */
final readonly class ProductCostOnReceipt implements ReceiptCosts
{
    public function __construct(private ChangeProductCost $changeCost)
    {
    }

    public function received(Company $company, Product $product, string $cost, Uuid $receiptId, ?Uuid $actorUserId): void
    {
        $this->changeCost->handle($company, $product, $cost, CostChangeSource::Receipt, $receiptId, $actorUserId);
    }
}
