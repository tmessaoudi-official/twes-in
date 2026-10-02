<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Application;

/** Another price list of the company already has the name. */
final class PriceListNameTaken extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another price list of this company has this name.');
    }
}
