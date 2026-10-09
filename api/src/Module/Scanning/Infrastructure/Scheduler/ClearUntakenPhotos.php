<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Scanning\Infrastructure\Scheduler;

use App\Module\Scanning\Application\PhonePhotos;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Clears, on the worker's schedule, the photos a phone sent that no tab took in time: a tab closed, or a person who
 * walked away. Taking one is already refused once its wait is over, so this only frees the rows; running it twice, or
 * on two workers, clears nothing more.
 */
#[AsPeriodicTask(frequency: '10 minutes')]
final readonly class ClearUntakenPhotos
{
    public function __construct(private PhonePhotos $photos, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        $cleared = $this->photos->clearUntaken();
        if ($cleared > 0) {
            $this->logger->info('{count} photos from paired phones were never taken and are cleared', ['count' => $cleared]);
        }
    }
}
