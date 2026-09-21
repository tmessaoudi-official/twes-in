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
    /**
     * @param string|null $reason what was being looked for, when the caller reached here by another road than an id
     *                            — a rectangle drawn for nothing is not "no such id", it is a rectangle with nothing
     *                            bound to it, and a surface that says the first sends the reader hunting for an id
     */
    public function __construct(?string $reason = null)
    {
        parent::__construct($reason ?? 'No such stock location.');
    }
}
