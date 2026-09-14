<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Events;

use App\Shared\Application\DomainEvents;
use App\Shared\Domain\DomainEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Domain events on Symfony's event dispatcher, each under its class name: a module reacts with
 * `#[AsEventListener(event: DeliveryNoteValidated::class)]`, and every listener has run when publish() returns.
 */
final readonly class SymfonyDomainEvents implements DomainEvents
{
    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->dispatcher->dispatch($event);
        }
    }
}
