<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Storage\StorageFull;
use App\Shared\Infrastructure\ApiPlatform\Output\StorageFullProblem;
use App\Shared\Infrastructure\Doctrine\CommittedWork;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A disk with no room left is the installation's state, not the request's mistake: PostgreSQL refusing a write with
 * SQLSTATE 53100 (disk_full, which rolls the transaction back) or the files' storage refusing one (`StorageFull`). The
 * request answers 507 `/problems/storage-full` in words, never the SQL or the path, and a critical line tells the people
 * looking after the installation, who alone can make room. A request that already committed a unit of work is left the
 * error it is, as `BusyListener` leaves it: « not saved » would be untrue. Before Symfony logs the exception (0) and API
 * Platform renders it (-96).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final readonly class StorageFullListener
{
    private const string DISK_FULL = '53100';

    public function __construct(private LoggerInterface $logger, private CommittedWork $committed)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($this->committed->any()) {
            return;
        }
        for ($seen = $event->getThrowable(); null !== $seen; $seen = $seen->getPrevious()) {
            $database = $seen instanceof DriverException && self::DISK_FULL === $seen->getSQLState();
            if ($database || $seen instanceof StorageFull) {
                $this->logger->critical("The installation's disk is full; answered storage full: the {disk} on {path}", [
                    'disk' => $database ? 'database' : 'files',
                    'path' => RoutedPath::of($event->getRequest()),
                ]);
                $event->setThrowable(new StorageFullProblem($seen));

                return;
            }
        }
    }
}
