<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\Replay;

/** No failed stock move of the company has this id: settled already, or never there. */
final class StockMoveFailureNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No failed stock move of this company has this id.');
    }
}
