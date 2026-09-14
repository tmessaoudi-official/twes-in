<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/** A category still holding subcategories or products is kept: they are moved first. */
final class ProductCategoryInUse extends \RuntimeException
{
    public function __construct(int $subcategories, int $products)
    {
        parent::__construct(\sprintf('The category still holds %d subcategories and %d products: move them first.', $subcategories, $products));
    }
}
