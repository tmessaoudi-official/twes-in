<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

final class StockLocationCodeTaken extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another location of this establishment already has this code.');
    }
}
