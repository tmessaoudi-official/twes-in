<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Application;

/** One floor is drawn once: two areas at the same level would be two plans of the same place. */
final class VenueLevelTaken extends \RuntimeException
{
    public string $field = 'level';

    public function __construct()
    {
        parent::__construct('This establishment already draws a floor at this level.');
    }
}
