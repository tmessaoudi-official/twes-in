<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\Scheduler;

use App\Licensing\Application\TellLapsingSubscriptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/** Every hour, tells the owners whose paid time ends soon; a run the worker missed is made up by the next. */
#[AsPeriodicTask(frequency: '1 hour')]
final readonly class TellLapsingSubscriptionsEveryHour
{
    public function __construct(private TellLapsingSubscriptions $tell, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        try {
            $told = $this->tell->handle();
            if ($told > 0) {
                $this->logger->info('{count} companies told their subscription ends soon', ['count' => $told]);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('Subscriptions ending soon could not be told: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
