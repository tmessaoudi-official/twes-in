<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\DomainEvents;
use App\Shared\Application\Transactions;
use App\Shared\Domain\DomainEvent;

/** Keeps what was published, and whether a transaction was still open when it was. */
final class RecordingDomainEvents implements DomainEvents
{
    /** @var list<DomainEvent> */
    public array $published = [];

    /** @var list<bool> one per published event */
    public array $whileInTransaction = [];

    public function __construct(private readonly ?Transactions $transactions = null)
    {
    }

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->published[] = $event;
            $this->whileInTransaction[] = $this->transactions?->active() ?? false;
        }
    }
}
