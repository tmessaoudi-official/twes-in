<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\Transactions;

/** Given the use case's transactions, refuses a change staged outside one, which the real adapter would drop unseen. */
final class RecordingLiveChanges implements LiveChanges
{
    /** @var list<LiveChange> */
    public array $staged = [];

    public function __construct(private readonly ?Transactions $transactions = null)
    {
    }

    public function stage(LiveChange $change): void
    {
        if (null !== $this->transactions && !$this->transactions->active()) {
            throw new \LogicException(\sprintf('%s was staged outside a transaction and would never be said.', $change->action));
        }
        $this->staged[] = $change;
    }
}
