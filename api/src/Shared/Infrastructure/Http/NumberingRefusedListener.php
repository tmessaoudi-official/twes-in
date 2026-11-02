<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A number refused while a document is issued (a day the computer's clock set back) reaches the kernel from every route
 * that issues one, each wrapping it in a bare conflict. It is answered here, once for all of them, as a 409 naming its
 * field, reason and parameters, read as a 422 is, so the screen says it in words rather than « conflict ». A refusal a
 * processor already answered as a field (a series edited on its settings page) is left as it is. Before API Platform
 * renders it (-96), like `RecordChangedListener`.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final class NumberingRefusedListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $thrown = $event->getThrowable();
        if ($thrown instanceof ValidationException) {
            return;
        }
        for ($seen = $thrown; null !== $seen; $seen = $seen->getPrevious()) {
            if ($seen instanceof InvalidNumbering && null !== $seen->reason) {
                $event->setThrowable(DomainRule::conflict($seen->field, $seen->getMessage(), $seen->reason, $seen->params, $seen));

                return;
            }
        }
    }
}
