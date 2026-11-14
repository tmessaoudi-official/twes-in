<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Installation\Application;

/** How many sign-ins were refused, and how many of those refusals locked the account they guessed at. */
final readonly class SignInCounts
{
    public function __construct(
        public int $failed,
        public int $locked,
    ) {
    }
}
