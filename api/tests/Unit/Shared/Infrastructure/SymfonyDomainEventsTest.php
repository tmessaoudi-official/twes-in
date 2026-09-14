<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Domain\DomainEvent;
use App\Shared\Infrastructure\Events\SymfonyDomainEvents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SymfonyDomainEventsTest extends TestCase
{
    public function testEachEventReachesTheListenersOfItsOwnClassInTheOrderPublished(): void
    {
        $dispatcher = new EventDispatcher();
        $validated = new class implements DomainEvent {};
        $cancelled = new class implements DomainEvent {};
        $heard = [];
        $dispatcher->addListener($validated::class, static function (DomainEvent $event) use (&$heard): void {
            $heard[] = ['validated', $event];
        });
        $dispatcher->addListener($cancelled::class, static function (DomainEvent $event) use (&$heard): void {
            $heard[] = ['cancelled', $event];
        });

        new SymfonyDomainEvents($dispatcher)->publish($cancelled, $validated);

        self::assertSame([['cancelled', $cancelled], ['validated', $validated]], $heard);
    }
}
