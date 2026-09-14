<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/** No product category of this company has the id: another company's category is not found either. */
final class ProductCategoryNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such product category.');
    }
}
