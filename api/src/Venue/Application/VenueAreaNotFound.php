<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Application;

/** No area of this company has the id: another company's floor is not found either. */
final class VenueAreaNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such area.');
    }
}
