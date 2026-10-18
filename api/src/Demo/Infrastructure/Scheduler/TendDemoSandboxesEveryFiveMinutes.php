<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Scheduler;

use App\Demo\Application\KeepSandboxPool;
use App\Demo\Application\RemoveSandboxes;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Tends the demo on the worker's schedule: takes away the sandboxes their visitors are done with (signed out, or away
 * past the demo's idle limit, so within five minutes of either) and the builds stopped halfway, then tops the pool up. A claim
 * tops it up at once; this catches a start, a switched-on demo, a raised maximum and a build that stopped halfway.
 */
#[AsPeriodicTask(frequency: '5 minutes')]
final readonly class TendDemoSandboxesEveryFiveMinutes
{
    public function __construct(private RemoveSandboxes $removals, private KeepSandboxPool $pool, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        try {
            $done = $this->removals->doneWith();
            if ($done > 0) {
                $this->logger->info('{count} demo sandboxes their visitors were done with were taken away', ['count' => $done]);
            }
            $removed = $this->removals->stale();
            if ($removed > 0) {
                $this->logger->info('{count} demo sandboxes stopped halfway were taken away', ['count' => $removed]);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('The demo sandboxes done with could not be taken away: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
        try {
            $queued = $this->pool->handle();
            if ($queued > 0) {
                $this->logger->info('{count} demo sandboxes queued', ['count' => $queued]);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('The demo sandboxes could not be topped up: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
