<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Application\Numbering\NumberAlreadyTaken;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A number refused while a document is issued reaches the kernel from every route that issues one, each wrapping it in
 * a bare conflict: a day the computer's clock set back, an establishment with no series for the document, a number
 * another document already carries. It is answered here, once for all of them, as a 409 naming its reason and
 * parameters, read as a 422 is, so the screen says which it is in words rather than guessing that the document changed
 * meanwhile. The routes' own catches stay as the answer should this listener not run. A refusal a processor already
 * answered as a field (a series edited on its settings page) is left as it is. Before API Platform renders it (-96),
 * like `RecordChangedListener`.
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
            $answer = self::answerTo($seen);
            if (null !== $answer) {
                $event->setThrowable($answer);

                return;
            }
        }
    }

    private static function answerTo(\Throwable $refused): ?ValidationException
    {
        if ($refused instanceof InvalidNumbering && null !== $refused->reason) {
            return DomainRule::conflict($refused->field, $refused->getMessage(), $refused->reason, $refused->params, $refused);
        }
        if ($refused instanceof NoNumberingSeries) {
            return DomainRule::conflict(DomainRule::WHOLE_REQUEST, $refused->getMessage(), 'no_numbering_series', ['establishment' => $refused->establishment, 'documentType' => $refused->documentType], $refused);
        }
        if ($refused instanceof NumberAlreadyTaken) {
            return DomainRule::conflict(DomainRule::WHOLE_REQUEST, $refused->getMessage(), 'series_number_taken', ['number' => $refused->number, 'establishment' => $refused->establishment, 'documentType' => $refused->documentType], $refused);
        }

        return null;
    }
}
