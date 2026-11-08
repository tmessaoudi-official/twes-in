<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Domain\Exception\InvalidFilter;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * A list asked to narrow itself by a value its filter has not (an address kept from before, or typed by hand: the
 * screens check their own filters first) is answered here, once for every list, as the export answers it: a 422 with
 * a violation on that filter, its reason `filter_refused`. Asking again would meet the same refusal, so the list says
 * to clear its filters rather than to try again. The refusal comes two ways: our own `InvalidFilter`, from a list's
 * search reader, and API Platform's check of a query parameter against its declared schema (a `withheld` neither yes
 * nor no, an `amount[min]` not a number), whose violations carry no reason. That one is taken only on a list's read, and
 * only when every violation names a parameter of the query, so a path variable, a written body or one record's read (a
 * price asked for a quantity) keeps its own answer.
 * `exception_to_status`'s 422 for `InvalidFilter` stays as the answer should this listener not run, as the export's own
 * catch does. Before API Platform renders it (-96), like `NumberingRefusedListener`.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final class InvalidFilterListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $thrown = $event->getThrowable();
        if ($thrown instanceof ValidationException) {
            $answer = self::parametersRefused($thrown, $event->getRequest());
            if (null !== $answer) {
                $event->setThrowable($answer);
            }

            return;
        }
        for ($seen = $thrown; null !== $seen; $seen = $seen->getPrevious()) {
            if ($seen instanceof InvalidFilter) {
                $event->setThrowable(DomainRule::refused($seen->filter, $seen->getMessage(), 'filter_refused', previous: $seen));

                return;
            }
        }
    }

    /** The same violations, each with the reason a list reads, when they are all a read's query parameters refused. */
    private static function parametersRefused(ValidationException $refused, Request $request): ?ValidationException
    {
        if (!$request->isMethodSafe() || !$request->attributes->get('_api_operation') instanceof CollectionOperationInterface) {
            return null;
        }
        $asked = array_map(strval(...), array_keys($request->query->all()));
        $answered = new ConstraintViolationList();
        foreach ($refused->getConstraintViolationList() as $violation) {
            $payload = $violation->getConstraint()?->payload;
            $path = $violation->getPropertyPath();
            $name = strstr($path, '[', true);
            if ((\is_array($payload) && isset($payload['reason'])) || !\in_array(false === $name ? $path : $name, $asked, true)) {
                return null;
            }
            $answered->add(DomainRule::violation($path, (string) $violation->getMessage(), 'filter_refused', invalidValue: $violation->getInvalidValue()));
        }
        if (0 === \count($answered)) {
            return null;
        }

        return new ValidationException($answered, previous: $refused);
    }
}
