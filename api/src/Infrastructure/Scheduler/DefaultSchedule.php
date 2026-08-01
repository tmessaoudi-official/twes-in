<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The application's recurring-work schedule.
 *
 * **This class exists because `infra/compose.yaml` runs `messenger:consume scheduler_default` and that transport
 * did not exist**, so the `scheduler` service crash-looped on the first `docker compose up` exactly as the
 * `worker` service did — see `config/packages/messenger.yaml` for the sibling defect. Symfony Scheduler derives
 * a transport named `scheduler_<name>` from each `#[AsSchedule('<name>')]` provider, and with no provider at all
 * there is no `scheduler_default` for the consumer to attach to.
 *
 * **The schedule is deliberately EMPTY, and that is honest rather than lazy.** `CLAUDE.md` records the scheduler
 * as wired with nothing scheduled until Wave 10 — recurring invoices, payment reminders and retention sweeps all
 * belong to features that do not exist yet. An empty schedule makes the consumer start and idle, which is the
 * correct behaviour for a topology that is complete before its work is; inventing a placeholder task would be
 * speculative infrastructure, and a task that does nothing is harder to notice than a schedule that is visibly
 * empty.
 *
 * **When work lands here, it takes the lock.** `infra/compose.yaml` pins this service to ONE replica because two
 * schedulers issue every recurring invoice twice, which in a billing product means charging a customer twice.
 * That constraint is currently enforced by a compose file and stops being true the moment the stack spans two
 * hosts, so the first real message added here must also call `->lock()` on the schedule against the store
 * `config/packages/lock.yaml` configures. Written down here rather than in a plan, because this is the file
 * somebody will be editing when it matters.
 */
#[AsSchedule('default')]
final class DefaultSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= new Schedule();
    }
}
