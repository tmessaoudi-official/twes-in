<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Application\BreachedPasswordCheck;

/** The three answers the port can give, chosen by the test. */
final class FakeBreachedPasswordCheck implements BreachedPasswordCheck
{
    public function __construct(private readonly ?bool $answer = false)
    {
    }

    public function isBreached(string $plainPassword): ?bool
    {
        return $this->answer;
    }
}
