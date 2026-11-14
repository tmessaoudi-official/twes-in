<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Application\Login\CallerAddress;

/** A caller whose address a test moves. */
final class FixedCallerAddress implements CallerAddress
{
    public function __construct(public string $address = '203.0.113.9')
    {
    }

    public function key(): string
    {
        return $this->address;
    }
}
