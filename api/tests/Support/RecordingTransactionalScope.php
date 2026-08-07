<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Support;

use Twes\Application\Shared\TransactionalScope;

/**
 * A {@see TransactionalScope} that runs the work immediately and RECORDS that it was asked to.
 *
 * **THE RECORDING IS THE POINT, and without it a handler test cannot tell a transaction from no transaction.** A
 * pass-through double that only forwarded the closure would make a handler that never opened a scope at all
 * indistinguishable from one that did — and what a use case has to guarantee here is that the number allocation and
 * the document write are ONE unit of work, because a partial commit either burns a document number on nothing or lets
 * two documents share one. So `$entered` is what a test asserts on, and deleting the `transactional()` call from a
 * handler turns that assertion red.
 *
 * `$depth` distinguishes "entered once" from "entered twice", which matters because DBAL implements a nested
 * `beginTransaction()` as a SAVEPOINT rather than as a second transaction — so a handler that wrapped its own two
 * writes separately would look transactional here while, in production, being two units of work whose second half can
 * roll back alone.
 *
 * Under `tests/` and never `src/`: a scope that commits nothing is not a transaction, and shipping one would mean
 * every write silently losing its atomicity.
 */
final class RecordingTransactionalScope implements TransactionalScope
{
    /** How many times a scope was opened. */
    public int $entered = 0;

    /** The deepest nesting reached — 1 for a single flat scope, 2 for a scope opened inside another. */
    public int $maxDepth = 0;

    private int $depth = 0;

    public function transactional(callable $work): mixed
    {
        ++$this->entered;
        ++$this->depth;
        $this->maxDepth = max($this->maxDepth, $this->depth);

        try {
            return $work();
        } finally {
            // IN A `finally`, so a throwing use case still unwinds the depth. Without it, one failing case would make
            // every later assertion in the same test read a depth that was never reached.
            --$this->depth;
        }
    }
}
