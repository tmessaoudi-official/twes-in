<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Application;

/** No spot of this company has the id: another company's rectangle is not found either. */
final class VenueSpotNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such spot.');
    }
}
