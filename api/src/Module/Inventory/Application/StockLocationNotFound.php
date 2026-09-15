<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

/** No stock location of this company has the id: another company's location is not found either. */
final class StockLocationNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such stock location.');
    }
}
