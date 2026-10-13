<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * In FrankenPHP's worker mode a connection outlives the request that opened it. When the server ends it meanwhile (a
 * database restarted for an upgrade or a rotated password, a crash), every later query on it fails and Doctrine does
 * not reconnect by itself: the first query fails, and only then is the connection let go. Each request first asks every
 * connection already open for a trivial answer, so that failure is this one's and not the request's own, and closes
 * one that gives none (DBAL closes it itself on a lost connection, not on every failure): the request's first query
 * then opens a new one. What Symfony's doctrine_ping_connection middleware does for a Messenger worker. A connection
 * not yet open is left alone. Before the session listener, the first to read.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 256)]
final readonly class DeadConnectionListener
{
    public function __construct(#[Autowire(service: 'doctrine')] private ManagerRegistry $registry)
    {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        foreach ($this->registry->getConnections() as $connection) {
            if ($connection instanceof Connection && $connection->isConnected() && !self::answers($connection)) {
                $connection->close();
            }
        }
    }

    private static function answers(Connection $connection): bool
    {
        try {
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());

            return true;
        } catch (DbalException) {
            return false;
        }
    }
}
