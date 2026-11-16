<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Scheduler;

use App\Module\Products\Application\Photo\LetRemovedPhotosGo;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/** Lets the removed product photos past the operator's time go, where the operator set one (LetRemovedPhotosGo). */
#[AsPeriodicTask(frequency: '1 hour')]
final readonly class LetRemovedPhotosGoEveryHour
{
    public function __construct(private LetRemovedPhotosGo $letGo, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        $count = $this->letGo->handle();
        if ($count > 0) {
            $this->logger->info('Let {count} removed product photos go, their time kept over', ['count' => $count]);
        }
    }
}
