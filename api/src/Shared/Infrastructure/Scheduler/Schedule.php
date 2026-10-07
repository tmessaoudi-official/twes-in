<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The worker's one schedule, `default`, consumed as the `scheduler_default` transport beside `async` (compose's worker).
 * Its tasks are declared where they belong, with #[AsPeriodicTask] or #[AsCronTask] on the module's own service. It
 * remembers its last run, so a task the worker was down for runs once when it comes back, not once per missed hour.
 * There is one worker, and each task is idempotent on its own, so the schedule takes no lock.
 */
#[AsSchedule]
final readonly class Schedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
