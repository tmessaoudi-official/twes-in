<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Scheduler;

use App\Identity\Infrastructure\Session\ExpiredSessions;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Takes away the sessions past their expiry, each row with what it held: PHP collects them only on a share of the
 * session starts, so a quiet installation would keep a signed-out person's session data for weeks.
 */
#[AsPeriodicTask(frequency: '1 hour')]
final readonly class RemoveExpiredSessionsEveryHour
{
    public function __construct(private ExpiredSessions $sessions, private ClockInterface $clock, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        $removed = $this->sessions->removeBefore($this->clock->now()->getTimestamp());
        if ($removed > 0) {
            $this->logger->info('{count} expired sessions were taken away', ['count' => $removed]);
        }
    }
}
