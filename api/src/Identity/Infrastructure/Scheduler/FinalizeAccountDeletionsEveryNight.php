<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Scheduler;

use App\Identity\Application\Account\FinalizeAccountDeletions;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/** Once a day, deletes the accounts whose deletion was asked for longer ago than the platform's days. */
#[AsPeriodicTask(frequency: '1 day', from: '%app.account.deletion_at%')]
final readonly class FinalizeAccountDeletionsEveryNight
{
    public function __construct(private FinalizeAccountDeletions $finalize, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        try {
            $run = $this->finalize->handle();
            if ($run['deleted'] > 0) {
                $this->logger->info('{count} accounts deleted', ['count' => $run['deleted']]);
            }
            if ($run['kept'] > 0) {
                $this->logger->warning('{count} accounts asked to be deleted are kept: each has become a company\'s only owner since', ['count' => $run['kept']]);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('Accounts asked to be deleted could not be deleted: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
