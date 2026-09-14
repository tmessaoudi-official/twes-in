<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

final class ProductCategoryNameTaken extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another product category of this company already has this name.');
    }
}
