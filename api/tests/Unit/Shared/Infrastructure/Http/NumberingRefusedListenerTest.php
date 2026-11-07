<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Module\Invoices\Application\InvoiceNumberTaken;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Shared\Infrastructure\Http\NumberingRefusedListener;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A number refused while issuing reaches every issuing route wrapped in a bare conflict: it is answered with its field,
 * reason and parameters, as a 422 is, so the screen says it in words rather than « conflict ».
 */
final class NumberingRefusedListenerTest extends TestCase
{
    public function testANumberRefusedUnderAConflictIsAnsweredWithItsReasonAndParameters(): void
    {
        $refused = new InvalidNumbering('issueDate', 'Check the clock.', 'before_last_day', ['last' => '2026-11-15', 'today' => '2026-11-14']);
        $event = self::event(new ConflictHttpException($refused->getMessage(), $refused));
        (new NumberingRefusedListener())($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(ValidationException::class, $answer);
        self::assertSame(409, $answer->getStatus());
        $violation = $answer->getConstraintViolationList()->get(0);
        self::assertSame(['issueDate', 'before_last_day'], [$violation->getPropertyPath(), $violation->getCode()]);
        $rule = $violation->getConstraint();
        self::assertInstanceOf(DomainRule::class, $rule);
        self::assertSame(['reason' => 'before_last_day', 'params' => ['last' => '2026-11-15', 'today' => '2026-11-14']], $rule->payload);
    }

    public function testAnEstablishmentNumberingNoSuchDocumentIsAnsweredWithWhichAndWhat(): void
    {
        $refused = new NoNumberingSeries('The establishment MAIN numbers no quote.', 'MAIN', 'quote');
        $event = self::event(new ConflictHttpException($refused->getMessage(), $refused));
        (new NumberingRefusedListener())($event);

        self::assertAnsweredWith($event, 'no_numbering_series', ['establishment' => 'MAIN', 'documentType' => 'quote']);
    }

    public function testANumberAnotherDocumentCarriesIsAnsweredWithTheNumberAndItsSeries(): void
    {
        $refused = new InvoiceNumberTaken('The number F-2026-0007 is already on another document.', 'F-2026-0007', 'SFAX', 'credit_note');
        $event = self::event(new ConflictHttpException($refused->getMessage(), $refused));
        (new NumberingRefusedListener())($event);

        self::assertAnsweredWith($event, 'series_number_taken', ['number' => 'F-2026-0007', 'establishment' => 'SFAX', 'documentType' => 'credit_note']);
    }

    public function testARefusalAlreadyAnsweredAsAFieldIsLeftAsItIs(): void
    {
        $refused = new InvalidNumbering('nextNumber', 'Resumes at 4.', 'sequence_from', ['next' => 4]);
        $answered = DomainRule::refused($refused->field, $refused->getMessage(), 'sequence_from', ['next' => 4], $refused);
        $event = self::event($answered);
        (new NumberingRefusedListener())($event);

        self::assertSame($answered, $event->getThrowable());
    }

    public function testANumberRefusedWithNoReasonIsLeftAsItIs(): void
    {
        $conflict = new ConflictHttpException('No reason.', new InvalidNumbering('issueDate', 'No reason.'));
        $event = self::event($conflict);
        (new NumberingRefusedListener())($event);

        self::assertSame($conflict, $event->getThrowable());
    }

    /** @param array<string, string> $params */
    private static function assertAnsweredWith(ExceptionEvent $event, string $reason, array $params): void
    {
        $answer = $event->getThrowable();
        self::assertInstanceOf(ValidationException::class, $answer);
        self::assertSame(409, $answer->getStatus());
        $violation = $answer->getConstraintViolationList()->get(0);
        self::assertSame([DomainRule::WHOLE_REQUEST, $reason], [$violation->getPropertyPath(), $violation->getCode()]);
        $rule = $violation->getConstraint();
        self::assertInstanceOf(DomainRule::class, $rule);
        self::assertSame(['reason' => $reason, 'params' => $params], $rule->payload);
    }

    private static function event(\Throwable $thrown): ExceptionEvent
    {
        return new ExceptionEvent(new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
            {
                throw new \LogicException('Not handled in this test.');
            }
        }, Request::create('/api/companies/x/invoices/y/issue', 'POST'), HttpKernelInterface::MAIN_REQUEST, $thrown);
    }
}
