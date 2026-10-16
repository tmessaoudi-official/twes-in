<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\RecordChanged;
use App\Shared\Infrastructure\ApiPlatform\Output\RecordChangedProblem;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers every save of an outdated version with one problem, whichever use case found it. It runs before Symfony
 * logs the exception (priority 0) and before API Platform renders it (-128): a conflict is a 4xx, not an error.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final class RecordChangedListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $changed = $event->getThrowable();
        if ($changed instanceof RecordChanged) {
            $event->setThrowable(new RecordChangedProblem($changed));
        }
    }
}
