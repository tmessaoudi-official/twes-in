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
 * The product whose homes are written, which the products module holds for the write. Its homes are part of what a
 * product form shows beside it, so a change of them moves the product's version: a list of homes read before another
 * person's change is refused rather than written over it, and a product form open elsewhere is told. A port this module
 * owns, answered by the products', so this module never writes their product itself (docs/SPEC.md § 7, audit
 * 2026-10-06 C-4). It runs inside the homes' transaction, so the version moves with them.
 */
interface HomedProducts
{
    /**
     * The product under a row lock, so another write of it waits; null when the company has none by this id. With a
     * version, the one the homes were read at, a product saved since is refused.
     *
     * @throws RecordChanged
     */
    public function held(Company $company, Uuid $productId, ?int $version): ?Product;

    /** Its homes changed: its version moves. */
    public function homesChanged(Product $product): void;
}
