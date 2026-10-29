<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Scheduler;

use App\Tenancy\Application\Invitation\TellRunOutInvitations;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/** Every hour, tells the senders of the invitations that ran out unused since; a run the worker missed is made up by the next. */
#[AsPeriodicTask(frequency: '1 hour')]
final readonly class TellRunOutInvitationsEveryHour
{
    public function __construct(private TellRunOutInvitations $tell, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        try {
            $told = $this->tell->handle();
            if ($told > 0) {
                $this->logger->info('{count} senders told their invitation ran out unused', ['count' => $told]);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('Run-out invitations could not be told: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
