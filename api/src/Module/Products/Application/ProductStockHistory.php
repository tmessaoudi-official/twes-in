<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Whether stock was ever moved for a product. Stock is the sum of its movements in the product's unit, so a product with
 * movements keeps its unit and stays goods. A port, so products name no class of the inventory module, which depends on
 * them and not the reverse; it answers whether that module is switched on or not, since its data is kept.
 */
interface ProductStockHistory
{
    public function hasMovements(Uuid $productId, Uuid $companyId): bool;
}
