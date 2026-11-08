<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Domain\Exception\InvalidFilter;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Shared\Infrastructure\Http\InvalidFilterListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * A list asked for a filter value it has not is answered on that filter with the reason `filter_refused`, as the export
 * answers one, so the list says to clear its filters rather than to try again.
 */
final class InvalidFilterListenerTest extends TestCase
{
    public function testAFilterRefusedIsAnsweredOnThatFilterWithItsReason(): void
    {
        $event = self::event(new InvalidFilter('issueDate[from]', '"yesterday" is not a day, YYYY-MM-DD.'));
        (new InvalidFilterListener())($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(ValidationException::class, $answer);
        self::assertSame(422, $answer->getStatus());
        self::assertCount(1, $answer->getConstraintViolationList());
        $violation = $answer->getConstraintViolationList()->get(0);
        self::assertSame(['issueDate[from]', 'filter_refused'], [$violation->getPropertyPath(), $violation->getCode()]);
        $rule = $violation->getConstraint();
        self::assertInstanceOf(DomainRule::class, $rule);
        self::assertSame(['reason' => 'filter_refused'], $rule->payload);
        self::assertInstanceOf(InvalidFilter::class, $answer->getPrevious());
    }

    public function testAQueryParameterItsSchemaRefusedIsAnsweredOnThatFilterWithItsReason(): void
    {
        $refused = self::parameterRefused(['amount[min]' => 'This value should be of type number.']);
        $event = self::event($refused, '/api/companies/x/instruments?amount%5Bmin%5D=abc');
        (new InvalidFilterListener())($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(ValidationException::class, $answer);
        self::assertSame(422, $answer->getStatus());
        self::assertCount(1, $answer->getConstraintViolationList());
        $violation = $answer->getConstraintViolationList()->get(0);
        self::assertSame(['amount[min]', 'filter_refused'], [$violation->getPropertyPath(), $violation->getCode()]);
        self::assertSame('This value should be of type number.', $violation->getMessage());
        $rule = $violation->getConstraint();
        self::assertInstanceOf(DomainRule::class, $rule);
        self::assertSame(['reason' => 'filter_refused'], $rule->payload);
        self::assertSame($refused, $answer->getPrevious());
    }

    public function testAPathVariableItsSchemaRefusedIsLeftAsItIs(): void
    {
        $refused = self::parameterRefused(['companyId' => 'This is not a valid UUID.']);
        $event = self::event($refused, '/api/companies/nope/invoices?status=draft');
        (new InvalidFilterListener())($event);

        self::assertSame($refused, $event->getThrowable());
    }

    public function testOneRecordsReadRefusedIsLeftAsItIs(): void
    {
        $refused = self::parameterRefused(['quantity' => 'This value should be of type number.']);
        $event = self::event($refused, '/api/companies/x/price-lists/y/price?quantity=abc', 'GET', new Get());
        (new InvalidFilterListener())($event);

        self::assertSame($refused, $event->getThrowable());
    }

    public function testARequestNoOperationAnswersIsLeftAsItIs(): void
    {
        $refused = self::parameterRefused(['status' => 'The value you selected is not a valid choice.']);
        $event = self::event($refused, '/api/companies/x/exports/invoices?status=x', 'GET', null);
        (new InvalidFilterListener())($event);

        self::assertSame($refused, $event->getThrowable());
    }

    public function testAWriteRefusedIsLeftAsItIs(): void
    {
        $refused = self::parameterRefused(['status' => 'The value you selected is not a valid choice.']);
        $event = self::event($refused, '/api/companies/x/invoices?status=x', 'POST');
        (new InvalidFilterListener())($event);

        self::assertSame($refused, $event->getThrowable());
    }

    /** @param array<string, string> $messages each refused parameter's message, as API Platform's validator writes it */
    private static function parameterRefused(array $messages): ValidationException
    {
        $violations = new ConstraintViolationList();
        foreach ($messages as $path => $message) {
            $violations->add(new ConstraintViolation($message, null, [], null, $path, 'abc'));
        }

        return new ValidationException($violations);
    }

    public function testAFilterRefusedUnderAnotherExceptionIsFoundBelowIt(): void
    {
        $event = self::event(new \RuntimeException('Wrapped.', 0, new InvalidFilter('status', '"paid-ish" is not one of draft, issued.')));
        (new InvalidFilterListener())($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(ValidationException::class, $answer);
        self::assertSame('status', $answer->getConstraintViolationList()->get(0)->getPropertyPath());
    }

    public function testARefusalAlreadyAnsweredIsLeftAsItIs(): void
    {
        $refused = new InvalidFilter('from', 'The period starts on or before the day it ends.');
        $answered = DomainRule::refused($refused->filter, $refused->getMessage(), 'filter_refused', previous: $refused);
        $event = self::event($answered);
        (new InvalidFilterListener())($event);

        self::assertSame($answered, $event->getThrowable());
    }

    public function testAnotherFailureIsLeftAsItIs(): void
    {
        $other = new \InvalidArgumentException('Not a filter.');
        $event = self::event($other);
        (new InvalidFilterListener())($event);

        self::assertSame($other, $event->getThrowable());
    }

    private static function event(\Throwable $thrown, string $uri = '/api/companies/x/invoices', string $method = 'GET', ?Operation $operation = new GetCollection()): ExceptionEvent
    {
        $request = Request::create($uri, $method);
        $request->attributes->set('_api_operation', $operation);

        return new ExceptionEvent(new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
            {
                throw new \LogicException('Not handled in this test.');
            }
        }, $request, HttpKernelInterface::MAIN_REQUEST, $thrown);
    }
}
