<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\Events\SymfonyDomainEvents;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
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

        new SymfonyDomainEvents($dispatcher, new NullLogger())->publish($cancelled, $validated);

        self::assertSame([['cancelled', $cancelled], ['validated', $validated]], $heard);
    }

    public function testAListenerThatFailsIsLoggedAndStopsNeitherTheOthersNorThePublisher(): void
    {
        $dispatcher = new EventDispatcher();
        $issued = new class implements DomainEvent {};
        $later = new class implements DomainEvent {};
        $heard = [];
        $failure = new \RuntimeException('the file storage is down');
        $dispatcher->addListener($issued::class, static function () use ($failure): never {
            throw $failure;
        }, 10);
        $dispatcher->addListener($issued::class, static function () use (&$heard): void {
            $heard[] = 'stock moved';
        });
        $dispatcher->addListener($later::class, static function () use (&$heard): void {
            $heard[] = 'later event heard';
        });
        $logger = self::recorder();

        new SymfonyDomainEvents($dispatcher, $logger)->publish($issued, $later);

        self::assertSame(['stock moved', 'later event heard'], $heard);
        self::assertCount(1, $logger->records);
        [$level, $context] = $logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertSame($issued::class, $context['event'] ?? null);
        self::assertSame($failure, $context['exception'] ?? null);
    }

    /** @return AbstractLogger&object{records: list<array{mixed, array<array-key, mixed>}>} */
    private static function recorder(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{mixed, array<array-key, mixed>}> */
            public array $records = [];

            /** @param array<array-key, mixed> $context */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $context];
            }
        };
    }
}
