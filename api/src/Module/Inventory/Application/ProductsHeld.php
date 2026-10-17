<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Products\Domain\Model\Product;
use App\Shared\Application\RecordChanged;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The product whose homes or reorder points are written, which the products module holds for the write. Both are shown
 * on the product's page beside its form, so a change of them moves the product's version: a list read before another
 * person's change is refused rather than written over it, and a product form open elsewhere is told. A port this module
 * owns, answered by the products', so this module never writes their product itself. It runs inside the write's
 * transaction, so the version moves with what changed.
 */
interface ProductsHeld
{
    /**
     * The product under a row lock, so another write of it waits; null when the company has none by this id. With a
     * version, the one the homes or points were read at, a product saved since is refused.
     *
     * @throws RecordChanged
     */
    public function held(Company $company, Uuid $productId, ?int $version): ?Product;

    /** Its homes or reorder points changed: its version moves. */
    public function changedBeside(Product $product): void;
}
