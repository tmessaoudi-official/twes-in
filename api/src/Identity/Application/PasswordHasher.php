<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application;

/** Hashing is a policy the framework owns (algorithm, cost); the use cases only need the result. */
interface PasswordHasher
{
    public function hash(string $plainPassword): string;
}
