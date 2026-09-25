<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\FirstSteps\Application;

/** A step of « Premiers pas » and whether the company has done it. */
final readonly class FirstStep
{
    public function __construct(public string $key, public bool $done)
    {
    }
}
