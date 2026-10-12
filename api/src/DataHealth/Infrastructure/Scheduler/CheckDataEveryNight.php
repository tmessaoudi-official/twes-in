<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataHealth\Infrastructure\Scheduler;

use App\DataHealth\Application\RunDataChecks;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Runs the data checks once a day at the hour `app.data_check.at` names, when the platform is quiet, and tells the
 * operators; each finding is logged with its detail, which they are not told. A daily task rather than a cron line,
 * which would need a cron parser the scheduler does not carry.
 */
#[AsPeriodicTask(frequency: '1 day', from: '%app.data_check.at%')]
final readonly class CheckDataEveryNight
{
    public function __construct(private RunDataChecks $checks, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        try {
            foreach ($this->checks->handle() as $run) {
                foreach ($run->findings as $finding) {
                    $this->logger->error('Data check {check} found, company {company}: {detail}', [
                        'check' => $run->check,
                        'company' => $finding->companyId?->toRfc4122() ?? '-',
                        'detail' => $finding->detail,
                    ]);
                }
                $this->logger->info('Data check {check}: {found} found in {seconds} s', ['check' => $run->check, 'found' => \count($run->findings), 'seconds' => round($run->seconds, 2)]);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('The data checks could not run: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
