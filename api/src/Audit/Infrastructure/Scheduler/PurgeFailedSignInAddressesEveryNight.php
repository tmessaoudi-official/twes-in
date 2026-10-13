<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Scheduler;

use App\Audit\Application\JournalAddresses;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Once a day, takes the address typed and the network address out of every failed sign-in older than
 * `app.audit.failed_sign_in_months`: long enough to read an attack back, not kept for good. Running it again changes
 * nothing already purged.
 */
#[AsPeriodicTask(frequency: '1 day', from: '%app.audit.purge_at%')]
final readonly class PurgeFailedSignInAddressesEveryNight
{
    public function __construct(
        private JournalAddresses $journal,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.audit.failed_sign_in_months')] private int $months,
    ) {
    }

    public function __invoke(): void
    {
        try {
            $purged = $this->journal->purgeFailedSignIns($this->months);
            $this->logger->info('Failed sign-in addresses older than {months} months purged from {count} journal entries', ['months' => $this->months, 'count' => $purged]);
        } catch (\Throwable $failure) {
            $this->logger->error('Failed sign-in addresses could not be purged: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
