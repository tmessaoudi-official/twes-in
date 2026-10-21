<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

/**
 * The databases the scale generator may write to: one named for it (`_scale`), or a test one: the main tree's
 * `_test`, a parallel writer's `_test_w<n>` or the snapshot gate's `_test_gate`, each followed by a paratest worker's
 * number (`_test4`, `_test_w2_4`, `_test_gate_4`, tests/parallel.php). Any other token is refused, so that a name
 * such as `twes_test_prod` is never taken for a test one. The application role's grants read it on every start, the
 * generator before it writes.
 */
final class ScaleDatabase
{
    public static function is(string $name): bool
    {
        return 1 === preg_match('/_(scale|test(\d+|(_w\d+|_gate)(_\d+)?)?)$/', $name);
    }
}
