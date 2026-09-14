<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Transactions;

/**
 * A transaction that is open exactly while its work runs. The functional tests cannot show a missing transaction,
 * because the test bundle keeps one open around every test; this fake can.
 */
final class FakeTransactions implements Transactions
{
    public int $committed = 0;
    private int $depth = 0;

    public function run(callable $work): mixed
    {
        ++$this->depth;
        try {
            $result = $work();
            ++$this->committed;

            return $result;
        } finally {
            --$this->depth;
        }
    }

    public function active(): bool
    {
        return $this->depth > 0;
    }
}
