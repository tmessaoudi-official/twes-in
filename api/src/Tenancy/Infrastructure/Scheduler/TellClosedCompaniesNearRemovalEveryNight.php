<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Scheduler;

use App\Tenancy\Application\Closing\TellClosedCompaniesNearRemoval;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/** Once a day, beside the removal itself, tells the owners of the closed companies whose removal draws near. */
#[AsPeriodicTask(frequency: '1 day', from: '%app.company.removal_at%')]
final readonly class TellClosedCompaniesNearRemovalEveryNight
{
    public function __construct(private TellClosedCompaniesNearRemoval $tell, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        try {
            $told = $this->tell->handle();
            if ($told > 0) {
                $this->logger->info('{count} closed companies\' owners told their removal draws near', ['count' => $told]);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('Closed companies near removal could not be told: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
