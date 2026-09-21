<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Application;

/** No piece of this company's building has the id: another company's wall is not found either. */
final class VenueStructureNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such piece of structure.');
    }
}
