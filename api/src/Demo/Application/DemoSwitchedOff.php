<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

/** The platform's operators switched the demo off: no visitor enters it, though sandboxes built before may still wait. */
final class DemoSwitchedOff extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The demo is switched off.');
    }
}
