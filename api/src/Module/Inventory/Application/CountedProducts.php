<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Products\Domain\Model\Product;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * That a product was counted, for the products to stop asking for its count. A port this module owns, answered by the
 * products', so neither calls into the other. It runs inside the count's transaction, so the product says it was counted
 * only when the count is kept.
 */
interface CountedProducts
{
    public function counted(Company $company, Product $product, ?Uuid $actorUserId): void;
}
