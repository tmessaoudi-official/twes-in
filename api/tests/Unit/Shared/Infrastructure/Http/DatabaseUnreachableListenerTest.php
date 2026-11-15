<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\ApiPlatform\Output\DatabaseUnreachableProblem;
use App\Shared\Infrastructure\Doctrine\CommittedWork;
use App\Shared\Infrastructure\Http\DatabaseUnreachableListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A database that does not answer (stopped for an update, restarting after a crash, its server gone) is a moment the
 * request waits out, not its error: 503 with a Retry-After, which the web says as the server out of reach and sends
 * again, never a 500 read as an unknown failure, and a critical line for the people who look after the installation.
 */
final class DatabaseUnreachableListenerTest extends TestCase
{
    /** @return iterable<string, array{\Throwable}> */
    public static function unreachable(): iterable
    {
        yield 'no connection could be opened' => [new ConnectionException(self::driverError('08006', 'connection to server failed: Connection refused'), null)];
        yield 'the connection was cut' => [new ConnectionLost(self::driverError('57P01', 'terminating connection due to administrator command'), null)];
        yield 'either, under the use case that met it' => [new \RuntimeException('The invoice could not be issued.', 0, new ConnectionLost(self::driverError('57P01', 'server closed the connection unexpectedly'), null))];
        // The session store runs on a PDO connection of its own, beside DBAL's, and throws PDO's own exception.
        yield 'the session store\'s connection was cut' => [self::pdoError('HY000', 'General error: 7 server closed the connection unexpectedly')];
        yield 'the session store could not connect' => [self::pdoError('08006', '[7] could not translate host name "postgres" to address')];
    }

    #[DataProvider('unreachable')]
    public function testADatabaseThatDoesNotAnswerAnswersUnreachableToBeSentAgain(\Throwable $down): void
    {
        $logged = self::logger();
        $event = self::event($down);
        (new DatabaseUnreachableListener($logged, new CommittedWork(), self::connection()))($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(DatabaseUnreachableProblem::class, $answer);
        self::assertSame(503, $answer->getStatus());
        self::assertSame('/problems/database-unreachable', $answer->getType());
        self::assertSame(5, $answer->getHeaders()['Retry-After'] ?? null, 'sent again a moment later');
        self::assertStringNotContainsString('SQLSTATE', $answer->getDetail());
        self::assertStringNotContainsString('connection to server', $answer->getDetail());
        self::assertSame(['critical'], $logged->levels, 'logged once, at the level that reaches the monitor');
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function otherFailures(): iterable
    {
        yield 'through DBAL' => [new DriverException(self::driverError('42P01', 'relation does not exist'), null)];
        yield 'on the session store\'s connection' => [self::pdoError('42P01', 'relation "sessions" does not exist')];
    }

    #[DataProvider('otherFailures')]
    public function testAnyOtherDatabaseFailureIsLeftAnError(\Throwable $other): void
    {
        $event = self::event($other);
        (new DatabaseUnreachableListener(self::logger(), new CommittedWork(), self::connection()))($event);

        self::assertSame($other, $event->getThrowable());
    }

    /** As a clash (BusyListener): a request that already kept something is left the error it is, never sent again. */
    public function testALostConnectionAfterARequestCommittedIsLeftAnError(): void
    {
        $committed = new CommittedWork();
        $committed->begin();
        $committed->commit();
        $lost = new ConnectionLost(self::driverError('57P01', 'terminating connection'), null);
        $event = self::event($lost);
        (new DatabaseUnreachableListener(self::logger(), $committed, self::connection()))($event);

        self::assertSame($lost, $event->getThrowable());
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

    /** The application's driver, never connected: only its exception converter is read. */
    private static function connection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_pgsql']);
    }

    /** As pdo_pgsql throws it: the SQLSTATE in the code, and in errorInfo once a statement ran. */
    private static function pdoError(string $state, string $message): \PDOException
    {
        $error = new class('SQLSTATE['.$state.']: '.$message) extends \PDOException {
            public function withState(string $state): self
            {
                $this->code = $state;

                return $this;
            }
        };

        return $error->withState($state);
    }

    private static function driverError(string $state, string $message): AbstractException
    {
        return new class('SQLSTATE['.$state.']: '.$message, $state) extends AbstractException {
        };
    }
}
