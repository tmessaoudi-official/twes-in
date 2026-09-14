<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * One unit of work that is stored whole or not at all. A use case that locks a row until its own writes are stored,
 * such as taking a document number, runs them through here.
 */
interface Transactions
{
    /**
     * Runs the work in a transaction, stores what it wrote and commits; anything it throws rolls it all back.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;

    /** Whether the code calling this runs inside a transaction. */
    public function active(): bool;
}
