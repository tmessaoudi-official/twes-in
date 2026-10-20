<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Records;

use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/**
 * A product category is deleted while it files no product and holds no subcategory, restored, and merged into another;
 * whoever writes products does it. Merged, what it filed and held goes under the category kept.
 */
final readonly class ProductCategoryRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [new RecordSubject('product_category', ProductPermission::WRITE, ProductsModule::KEY, ['name'])];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('product', 'category_id', 'product_category', RecordMerge::Moves, 'A product is filed under its category.'),
            RecordReference::uses('product_category', 'parent_id', 'product_category', RecordMerge::Moves, 'A subcategory sits under its category.'),
        ];
    }
}
