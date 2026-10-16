<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Events;

use App\Shared\Application\DomainEvents;
use App\Shared\Domain\Event\DomainEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Domain events on Symfony's event dispatcher, each under its class name: a module reacts with
 * `#[AsEventListener(event: DeliveryNoteValidated::class)]`, and every listener has run when publish() returns.
 *
 * An event is published once its change is committed, so a listener that fails can undo nothing: it is logged, and the
 * other listeners still run, as the stock an issued invoice takes out must not wait on the storage of its PDF. That is
 * why the listeners are called here one by one rather than through dispatch(), which stops at the first that throws
 * (and which the profiler traces, the one thing lost).
 */
final readonly class SymfonyDomainEvents implements DomainEvents
{
    public function __construct(private EventDispatcherInterface $dispatcher, private LoggerInterface $logger)
    {
    }

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $name = $event::class;
            foreach ($this->dispatcher->getListeners($name) as $listener) {
                try {
                    $listener($event, $name, $this->dispatcher);
                } catch (\Throwable $failure) {
                    $this->logger->error('A listener of {event} failed after its change was committed; the others still ran: {failure}', [
                        'event' => $name,
                        'listener' => self::named($listener),
                        'failure' => $failure->getMessage(),
                        'exception' => $failure,
                    ]);
                }
            }
        }
    }

    private static function named(callable $listener): string
    {
        if (\is_array($listener)) {
            return (\is_object($listener[0]) ? $listener[0]::class : (string) $listener[0]).'::'.$listener[1];
        }

        return $listener instanceof \Closure ? 'a closure' : get_debug_type($listener);
    }
}
