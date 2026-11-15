<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\ApiPlatform\Output\DatabaseUnreachableProblem;
use App\Shared\Infrastructure\Doctrine\CommittedWork;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A database that does not answer is a moment the request waits out, not its error: no connection opened (stopped for
 * an update, restarting after a crash) or one cut while it ran (DBAL's ConnectionLost, a kind of ConnectionException).
 * The transaction went with the connection, so the request answers 503 with a Retry-After, which the web says as the
 * server out of reach and lets the person send again, never the 500 an unknown failure is read as, and never the
 * driver's words (they name the host). A critical line tells the people who look after the installation. A request
 * that already committed a unit of work is left the error it is, as `BusyListener` leaves it: sent again, what committed
 * would be made twice. The session store runs on a PDO connection of its own and throws PDO's exception, which the
 * application's driver reads as DBAL reads its own, so a database gone while a session opens is said the same way.
 * Before Symfony logs the exception (0) and API Platform renders it (-96).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final readonly class DatabaseUnreachableListener
{
    public function __construct(private LoggerInterface $logger, private CommittedWork $committed, private Connection $connection)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($this->committed->any()) {
            return;
        }
        for ($seen = $event->getThrowable(); null !== $seen; $seen = $seen->getPrevious()) {
            if ($seen instanceof ConnectionException || ($seen instanceof \PDOException && $this->lostConnection($seen))) {
                $this->logger->critical('The database did not answer; answered unreachable: {kind} on {path}', [
                    'kind' => (new \ReflectionClass($seen))->getShortName(),
                    'path' => RoutedPath::of($event->getRequest()),
                ]);
                $event->setThrowable(new DatabaseUnreachableProblem($seen));

                return;
            }
        }
    }

    /** Whether the application's own driver reads this PDO failure as no connection, opened or kept. */
    private function lostConnection(\PDOException $failure): bool
    {
        $state = $failure->errorInfo[0] ?? $failure->getCode();
        $driverError = new class($failure->getMessage(), \is_string($state) ? $state : null, $failure) extends AbstractException {
            public function __construct(string $message, ?string $state, \PDOException $previous)
            {
                parent::__construct($message, $state, 0, $previous);
            }
        };

        return $this->connection->getDriver()->getExceptionConverter()->convert($driverError, null) instanceof ConnectionException;
    }
}
