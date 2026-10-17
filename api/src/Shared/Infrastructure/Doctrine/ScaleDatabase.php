<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

/**
 * The databases the scale generator may write to: one named for it (`_scale`), or a test one, a parallel writer's
 * `_test_w<n>` included. The application role's grants read it on every start, the generator before it writes.
 */
final class ScaleDatabase
{
    public static function is(string $name): bool
    {
        return 1 === preg_match('/_(scale|test\d*(_w\d+)?)$/', $name);
    }
}
