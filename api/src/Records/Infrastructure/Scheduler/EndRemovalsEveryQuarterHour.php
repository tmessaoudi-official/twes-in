<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Infrastructure\Scheduler;

use App\Records\Application\EndDueRemovals;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Makes final the deletes whose day is over and lets their copies go, on the worker's schedule. Nothing waits on it to
 * refuse a restore, which looks at the delete's end itself: it only lets the copy, a vendor's bank details with it, go
 * soon after.
 */
#[AsPeriodicTask(frequency: '15 minutes')]
final readonly class EndRemovalsEveryQuarterHour
{
    public function __construct(private EndDueRemovals $ends, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        try {
            $ended = $this->ends->handle();
            if ($ended > 0) {
                $this->logger->info('{count} deletes made final and their copies deleted', ['count' => $ended]);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('Deletes could not be made final: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
