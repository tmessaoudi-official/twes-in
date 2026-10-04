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
     * Every home this product has, by establishment code and then in order, the main home first.
     *
     * @return list<ProductHomeLocation>
     */
    public function ofProduct(Uuid $productId, Uuid $companyId): array;

    /**
     * The homes this product has in that establishment, the main one first.
     *
     * @return list<ProductHomeLocation>
     */
    public function ofProductInEstablishment(Uuid $productId, Uuid $establishmentId): array;

    /**
     * The homes of each of these products, keyed by the product's identifier, each list in order. One read serves a whole picker: a list
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
