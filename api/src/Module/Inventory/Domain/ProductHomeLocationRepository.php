<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

interface ProductHomeLocationRepository
{
    /**
     * Every home this product has, one per establishment, by establishment code.
     *
     * @return list<ProductHomeLocation>
     */
    public function ofProduct(Uuid $productId, Uuid $companyId): array;

    /** The home this product has in that establishment, or none. */
    public function ofProductInEstablishment(Uuid $productId, Uuid $establishmentId): ?ProductHomeLocation;

    /**
     * The homes of each of these products, keyed by the product's identifier. One read serves a whole picker: a list
     * that asked per product would ask once per row.
     *
     * @param list<Uuid> $productIds
     *
     * @return array<string, list<ProductHomeLocation>>
     */
    public function ofProducts(array $productIds, Uuid $companyId): array;

    public function save(ProductHomeLocation $home): void;

    public function remove(ProductHomeLocation $home): void;
}
