<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Application\Storage\StorageFull;
use App\Shared\Infrastructure\ApiPlatform\Output\StorageFullProblem;
use App\Shared\Infrastructure\Doctrine\CommittedWork;
use App\Shared\Infrastructure\Http\StorageFullListener;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A disk with no room left, the database's or the files', is the installation's state, not the request's error: the
 * request answers 507 `/problems/storage-full`, saying so in words and never the SQL or the path, and the installation's
 * people are told by the critical line it logs.
 */
final class StorageFullListenerTest extends TestCase
{
    private const string SQL = 'INSERT INTO invoice (number) VALUES (?)';

    /** @return iterable<string, array{\Throwable}> */
    public static function fullDisks(): iterable
    {
        yield "the database's disk" => [new DriverException(self::driverError('53100'), new Query(self::SQL, [], []))];
        yield "the files' disk" => [new StorageFull('The storage has no room left for companies/c1/f1.')];
        yield 'either, under the use case that met it' => [new \RuntimeException('The invoice could not be issued.', 0, new DriverException(self::driverError('53100'), null))];
    }

    #[DataProvider('fullDisks')]
    public function testAFullDiskAnswersStorageFullInWordsAndTellsThePeopleWhoLookAfterIt(\Throwable $full): void
    {
        $logged = self::logger();
        $event = self::event($full);
        (new StorageFullListener($logged, new CommittedWork()))($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(StorageFullProblem::class, $answer);
        self::assertSame(507, $answer->getStatus());
        self::assertSame('/problems/storage-full', $answer->getType());
        self::assertStringContainsString('disk is full', $answer->getDetail());
        self::assertStringNotContainsString('INSERT', $answer->getDetail());
        self::assertStringNotContainsString('companies/', $answer->getDetail());
        self::assertSame(['critical'], $logged->levels, 'logged once, at the level that reaches the monitor');
    }

    public function testAnyOtherDatabaseFailureIsLeftAnError(): void
    {
        $other = new DriverException(self::driverError('42P01'), null);
        $event = self::event($other);
        (new StorageFullListener(self::logger(), new CommittedWork()))($event);

        self::assertSame($other, $event->getThrowable());
    }

    /** As a clash (BusyListener): a request that already kept something is left the error it is, never said as unsaved. */
    public function testAFullDiskAfterARequestCommittedIsLeftAnError(): void
    {
        $committed = new CommittedWork();
        $committed->begin();
        $committed->commit();
        $full = new StorageFull('The storage has no room left for companies/c1/f1.');
        $event = self::event($full);
        (new StorageFullListener(self::logger(), $committed))($event);

        self::assertSame($full, $event->getThrowable());
    }

    /** @return AbstractLogger&object{levels: list<string>} */
    private static function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->levels[] = \is_string($level) ? $level : get_debug_type($level);
            }
        };
    }

    private static function event(\Throwable $thrown): ExceptionEvent
    {
        return new ExceptionEvent(new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
            {
                throw new \LogicException('Not handled in this test.');
            }
        }, Request::create('/api/companies/x/invoices', 'POST'), HttpKernelInterface::MAIN_REQUEST, $thrown);
    }

    private static function driverError(string $state): AbstractException
    {
        return new class('SQLSTATE['.$state.']: '.self::SQL, $state) extends AbstractException {
        };
    }
}
