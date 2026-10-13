<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Infrastructure\Doctrine\DeadConnectionListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A worker outlives its requests, and so do its database connections. One the server ended meanwhile (a restart, a
 * rotated password, a crash) fails every query after it, and Doctrine does not reconnect by itself: each request
 * checks the connections it kept, and one that no longer answers is closed, so the next query opens a new one.
 */
final class DeadConnectionListenerTest extends KernelTestCase
{
    public function testAConnectionTheServerEndedIsReplacedBeforeTheRequestUsesIt(): void
    {
        $connection = $this->standalone();
        $first = $connection->fetchOne('SELECT pg_backend_pid()');
        $this->owner()->executeStatement('SELECT pg_terminate_backend(?)', [$first]);

        $this->listener($connection)->onRequest($this->event(HttpKernelInterface::MAIN_REQUEST));

        self::assertNotSame($first, $connection->fetchOne('SELECT pg_backend_pid()'));
        $connection->close();
    }

    public function testALiveConnectionIsKept(): void
    {
        $connection = $this->standalone();
        $first = $connection->fetchOne('SELECT pg_backend_pid()');

        $this->listener($connection)->onRequest($this->event(HttpKernelInterface::MAIN_REQUEST));

        self::assertSame($first, $connection->fetchOne('SELECT pg_backend_pid()'));
        $connection->close();
    }

    public function testAConnectionNotYetOpenIsNotOpened(): void
    {
        $connection = $this->standalone();

        $this->listener($connection)->onRequest($this->event(HttpKernelInterface::MAIN_REQUEST));

        self::assertFalse($connection->isConnected());
    }

    public function testItRunsOnEveryRequestBeforeTheSessionAndTheFirewall(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $found = null;
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            if (\is_array($listener) && $listener[0] instanceof DeadConnectionListener) {
                $found = $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener);
            }
        }

        self::assertNotNull($found, 'registered on kernel.request');
        self::assertGreaterThan(128, $found, 'before the session listener, the first to read the database');
    }

    public function testTheMessengerWorkerChecksItsConnectionsBeforeEachMessage(): void
    {
        $config = (string) file_get_contents(\dirname(__DIR__, 3).'/config/packages/messenger.yaml');

        self::assertMatchesRegularExpression('/^ {8}default_bus: messenger\.bus\.default$/m', $config);
        self::assertMatchesRegularExpression('/^ {12}messenger\.bus\.default:\n {16}middleware:\n(?: {20}- \S+\n)* {20}- doctrine_ping_connection$/m', $config);
    }

    /** A connection of its own, as the worker's are: the suite's own runs inside a transaction it must keep. */
    private function standalone(): Connection
    {
        $params = $this->owner()->getParams();
        $text = static fn (string $key): string => \is_scalar($params[$key] ?? null) ? (string) $params[$key] : '';

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $text('host'),
            'port' => (int) $text('port'),
            'user' => $text('user'),
            'password' => $text('password'),
            'dbname' => $text('dbname'),
        ]);
    }

    private function owner(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.owner_connection');
    }

    private function listener(Connection $connection): DeadConnectionListener
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnections')->willReturn(['default' => $connection]);

        return new DeadConnectionListener($registry);
    }

    private function event(int $type): RequestEvent
    {
        return new RequestEvent(self::bootKernel(), Request::create('/api/health'), $type);
    }
}
