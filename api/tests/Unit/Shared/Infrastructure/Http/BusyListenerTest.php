<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\ApiPlatform\Output\BusyProblem;
use App\Shared\Infrastructure\Doctrine\CommittedWork;
use App\Shared\Infrastructure\Http\BusyListener;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Two writes meeting in the database (a number both took, a deadlock, a lock waited on too long) are a moment, not an
 * error: the request answers 409 `/problems/busy`, to be sent again, and never the SQL that clashed.
 */
final class BusyListenerTest extends TestCase
{
    private const string SQL = 'INSERT INTO customer (number) VALUES (?)';

    /** @return iterable<string, array{DriverException}> */
    public static function clashes(): iterable
    {
        yield 'a number both took' => [new UniqueConstraintViolationException(self::driverError('23505'), new Query(self::SQL, [], []))];
        yield 'a deadlock' => [new DeadlockException(self::driverError('40P01'), null)];
        yield 'a lock waited on too long' => [new LockWaitTimeoutException(self::driverError('55P03'), null)];
    }

    #[DataProvider('clashes')]
    public function testAClashInTheDatabaseAnswersBusyWithoutItsSql(DriverException $clash): void
    {
        $event = self::event($clash);
        (new BusyListener(new NullLogger(), self::committed(false)))($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(BusyProblem::class, $answer);
        self::assertSame(409, $answer->getStatus());
        self::assertSame('/problems/busy', $answer->getType());
        self::assertSame('1', $answer->getHeaders()['Retry-After'] ?? null, 'sent again a moment later');
        self::assertStringNotContainsString('INSERT', $answer->getDetail());
        self::assertStringNotContainsString('SQLSTATE', $answer->getDetail());
    }

    public function testAClashWrappedByTheUseCaseIsFoundUnderIt(): void
    {
        $event = self::event(new \RuntimeException('The invoice could not be issued.', 0, new DeadlockException(self::driverError('40P01'), null)));
        (new BusyListener(new NullLogger(), self::committed(false)))($event);

        self::assertInstanceOf(BusyProblem::class, $event->getThrowable());
    }

    public function testAnyOtherDatabaseFailureIsLeftAnError(): void
    {
        $other = new DriverException(self::driverError('42P01'), null);
        $event = self::event($other);
        (new BusyListener(new NullLogger(), self::committed(false)))($event);

        self::assertSame($other, $event->getThrowable());
    }

    /**
     * A request that committed a unit of work before the clash (a second transaction, or a listener after the first)
     * kept something: sent again, that would be made twice. It stays the error it is.
     */
    public function testAClashAfterARequestCommittedIsLeftAnError(): void
    {
        $clash = new UniqueConstraintViolationException(self::driverError('23505'), null);
        $event = self::event($clash);
        (new BusyListener(new NullLogger(), self::committed(true)))($event);

        self::assertSame($clash, $event->getThrowable());
    }

    private static function committed(bool $any): CommittedWork
    {
        $committed = new CommittedWork();
        if ($any) {
            $committed->begin();
            $committed->begin();
            $committed->commit();
            $committed->commit();
        }

        return $committed;
    }

    public function testOnlyAnOutermostCommitCountsAndANewRequestStartsWithNone(): void
    {
        $committed = new CommittedWork();
        $committed->begin();
        $committed->begin();
        $committed->commit();
        self::assertFalse($committed->any(), 'an inner level committed, the outer one has not');
        $committed->rollBack();
        self::assertFalse($committed->any(), 'rolled back: nothing kept');
        $committed->begin();
        $committed->commit();
        self::assertTrue($committed->any());
        $committed->reset();
        self::assertFalse($committed->any());
    }

    private static function event(\Throwable $thrown): ExceptionEvent
    {
        return new ExceptionEvent(new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
            {
                throw new \LogicException('Not handled in this test.');
            }
        }, Request::create('/api/companies/x/customers', 'POST'), HttpKernelInterface::MAIN_REQUEST, $thrown);
    }

    private static function driverError(string $state): AbstractException
    {
        return new class('SQLSTATE['.$state.']: '.self::SQL, $state) extends AbstractException {
        };
    }
}
