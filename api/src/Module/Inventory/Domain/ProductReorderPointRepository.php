<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

interface ProductReorderPointRepository
{
    /**
     * Every reorder point this product has, one per establishment, by establishment code.
     *
     * @return list<ProductReorderPoint>
     */
    public function ofProduct(Uuid $productId, Uuid $companyId): array;

    /** The reorder point this product has in that establishment, or none. */
    public function ofProductInEstablishment(Uuid $productId, Uuid $establishmentId): ?ProductReorderPoint;

    public function save(ProductReorderPoint $point): void;

    public function remove(ProductReorderPoint $point): void;
}
