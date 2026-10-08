<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use Symfony\Component\Uid\Uuid;

interface ProductPhotoRepository
{
    /** @return list<ProductPhoto> the photos in a product's gallery, in their order; a removed one is not among them */
    public function ofProduct(Uuid $productId, Uuid $companyId): array;

    /** One of a product's photos, a removed one included: its removal can still be undone. */
    public function ofIdInProduct(Uuid $id, Uuid $productId, Uuid $companyId): ?ProductPhoto;

    /**
     * The main photo of each of several products, in one read: a list page names its rows' at once.
     *
     * @param list<Uuid> $productIds
     *
     * @return array<string, string> the photo id by product id, both RFC 4122, only for the products that have one
     */
    public function mainPhotoIdsOf(Uuid $companyId, array $productIds): array;

    /**
     * Holds the product's row until the transaction ends, so two photos sent at once cannot both take the last place
     * in the gallery, nor both become the first main photo.
     */
    public function lockProduct(Uuid $productId): void;

    public function save(ProductPhoto $photo): void;
}
