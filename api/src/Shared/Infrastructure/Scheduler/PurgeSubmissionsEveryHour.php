<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use App\Shared\Infrastructure\Submissions\SubmissionClaims;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Forgets the keys sent with writes once their hours are over (`app.submissions.kept_hours`): an attempt is retried
 * within minutes, and a kept answer carries what the write answered, a customer's name included.
 */
#[AsPeriodicTask(frequency: '1 hour')]
final readonly class PurgeSubmissionsEveryHour
{
    public function __construct(
        private SubmissionClaims $claims,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire('%app.submissions.kept_hours%')] private int $keptHours,
    ) {
    }

    public function __invoke(): void
    {
        $forgotten = $this->claims->forgetBefore($this->clock->now()->modify(\sprintf('-%d hours', $this->keptHours)));
        if ($forgotten > 0) {
            $this->logger->info('{count} submission keys past their hours were forgotten', ['count' => $forgotten]);
        }
    }
}
