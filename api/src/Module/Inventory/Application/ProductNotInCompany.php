<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

/** No product of this company has that id, which the surface answers 404: it is the thing being addressed. */
final class ProductNotInCompany extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No product of this company has this id.');
    }
}
