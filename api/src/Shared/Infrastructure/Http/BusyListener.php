<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\ApiPlatform\Output\BusyProblem;
use App\Shared\Infrastructure\Doctrine\CommittedWork;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Two writes meeting in the database are a moment, not an error: a value both took that a unique index holds once, a
 * deadlock PostgreSQL broke by rolling one back, a lock waited on past the timeout. Whatever use case met it, the
 * transaction was rolled back, so the request answers 409 `/problems/busy` to be sent again, and never carries the SQL
 * that clashed (it names tables and values). A use case that can say what clashed in its own words (a customer number
 * taken) catches it first; this is what reaches the kernel unsaid. Logged as a warning: a route with no check of its
 * own before a unique index answers busy on every try, and that warning, recurring on one path, is the only tell. A
 * request that already committed a unit of work is left the error it is: sent again, what committed would be made
 * twice (a request held to a key then answers as made with its answer lost, `SubmissionGuard`). Before Symfony logs the exception (0) and API Platform
 * renders it (-96), like `RecordChangedListener`.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final readonly class BusyListener
{
    public function __construct(private LoggerInterface $logger, private CommittedWork $committed)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($this->committed->any()) {
            return;
        }
        for ($seen = $event->getThrowable(); null !== $seen; $seen = $seen->getPrevious()) {
            if ($seen instanceof UniqueConstraintViolationException || $seen instanceof DeadlockException || $seen instanceof LockWaitTimeoutException) {
                $this->logger->warning('Two writes met in the database; answered busy: {kind} on {path}', [
                    'kind' => (new \ReflectionClass($seen))->getShortName(),
                    'path' => RoutedPath::of($event->getRequest()),
                ]);
                $event->setThrowable(new BusyProblem($seen));

                return;
            }
        }
    }
}
