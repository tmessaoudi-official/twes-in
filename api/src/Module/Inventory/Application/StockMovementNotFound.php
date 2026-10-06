<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

/** No stock movement of the company has this id. */
final class StockMovementNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No stock movement of this company has this id.');
    }
}
