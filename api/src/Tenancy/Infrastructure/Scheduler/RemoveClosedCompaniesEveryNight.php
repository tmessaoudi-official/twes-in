<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Scheduler;

use App\Tenancy\Application\Company\RemoveClosedCompanies;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/** Once a day, removes the companies closed down whose records no longer have to be kept. */
#[AsPeriodicTask(frequency: '1 day', from: '%app.company.removal_at%')]
final readonly class RemoveClosedCompaniesEveryNight
{
    public function __construct(private RemoveClosedCompanies $remove, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        try {
            $removed = $this->remove->handle();
            if ($removed > 0) {
                $this->logger->info('{count} closed companies removed at the end of their retention', ['count' => $removed]);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('Closed companies could not be removed: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
