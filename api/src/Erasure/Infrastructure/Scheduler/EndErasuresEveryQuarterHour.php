<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Infrastructure\Scheduler;

use App\Erasure\Application\EndDueErasures;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Deletes the copies of erasures whose 24 hours are over, on the worker's schedule. Nothing waits on it to refuse an
 * undo, which looks at the erasure's end itself: it only lets the copy and its files go soon after.
 */
#[AsPeriodicTask(frequency: '15 minutes')]
final readonly class EndErasuresEveryQuarterHour
{
    public function __construct(private EndDueErasures $ends, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        try {
            $ended = $this->ends->handle();
            if ($ended > 0) {
                $this->logger->info('{count} erasures ended and their copies deleted', ['count' => $ended]);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('Erasures could not be ended: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
