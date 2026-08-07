<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Functional\Http;

use ApiPlatform\Metadata\Post;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Twes\Application\Document\CreateInvoiceHandler;
use Twes\Application\Document\IssueInvoiceHandler;
use Twes\Domain\Document\DocumentNumberAllocator;
use Twes\Tests\Support\FixedIdGenerator;
use Twes\Tests\Support\InMemoryDocumentNumberSequence;
use Twes\Tests\Support\InMemoryInvoiceRepository;
use Twes\Tests\Support\RecordingTransactionalScope;
use Twes\UI\Http\ApiResource\NewFixedChargeInput;
use Twes\UI\Http\ApiResource\NewInvoiceInput;
use Twes\UI\Http\ApiResource\NewInvoiceLineInput;
use Twes\UI\Http\State\CreateInvoiceProcessor;
use Twes\UI\Http\State\IssueInvoiceProcessor;

/**
 * THE TRANSPORT BOUNDARY OF THE WRITE PATH — parsing, and how each way it can fail becomes a status code.
 *
 * The twin of `InvoiceProviderTest` on the read side, and driven the same way: the processors are invoked directly
 * rather than through a booted kernel, because the subject is the TRANSLATION — strings into the domain's vocabulary,
 * and domain refusals into HTTP statuses. `InvoiceWriteSurfaceTest` covers what only a real kernel can show
 * (deserialization, validation, the published contract); `InvoiceLifecycleTest` covers what only a real database can.
 *
 * **THE STATUS SPLIT IS THE POINT OF THIS FILE.** A domain refusal reaching Symfony untranslated is a 500, so every
 * caller-fixable refusal must be caught and re-thrown — and the split has to be by exception hierarchy rather than by
 * message, because messages are prose. `\InvalidArgumentException` and `\DomainException` are the caller's fault
 * (422); a bare `\LogicException` or a `\RuntimeException` is ours (500, `error.internal`). Getting that backwards
 * either hides our bugs as client errors or reports the client's as server faults, and both are invisible in a green
 * suite.
 */
#[CoversClass(CreateInvoiceProcessor::class)]
#[CoversClass(IssueInvoiceProcessor::class)]
final class InvoiceWriteProcessorTest extends TestCase
{
    private const FIRST_ID = '0199a5b2-0000-7000-8000-000000000001';

    /** A well-formed payload becomes a draft resource, with the totals computed and the number absent. */
    public function testAWellFormedPayloadBecomesADraftResource(): void
    {
        $resource = self::create(self::validInput());

        self::assertSame(self::FIRST_ID, $resource->id);
        self::assertSame('draft', $resource->state);
        self::assertSame('TND', $resource->currency);
        self::assertNull($resource->number, 'a draft has no rendered number');
        self::assertNull($resource->sequence);
        self::assertCount(2, $resource->lines);
        self::assertCount(1, $resource->fixedCharges);

        // The figures are the representation's, computed from the aggregate — proof that the processor produced a real
        // document rather than echoing its input back.
        self::assertSame('0.100', $resource->fixedCharges[0]->amount);
        self::assertNotSame('0', $resource->totals->total, 'the total is computed, not empty');
    }

    /**
     * **EVERY MONETARY AND QUANTITY FIELD IS A JSON STRING IN THE CREATE RESPONSE TOO.**
     *
     * Asserted separately from the read path rather than assumed to follow from it: `POST` and `GET` share
     * `InvoiceRepresentation`, and this is what would fail if a future change gave the write path its own serialisation.
     * JSON has one number type and it is a double — `0.100 TND`, exactly 100 millimes, stops being exact the moment it
     * becomes one.
     */
    public function testTheCreateResponseCarriesEveryAmountAsAQuotedString(): void
    {
        $json = json_encode(self::create(self::validInput()), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"amount":"0.100"', $json);

        foreach (['quantity', 'unitNet', 'vatRate', 'net', 'vat', 'amount', 'subtotalNet', 'vatTotal', 'total'] as $f) {
            self::assertDoesNotMatchRegularExpression(
                '/"' . $f . '":[^"]/',
                $json,
                \sprintf('"%s" must be a quoted decimal string, never a JSON number: %s', $f, $json),
            );
        }
    }

    /**
     * A DOMAIN REFUSAL A CALLER CAN ACT ON IS A **422**, and the domain's own message travels with it.
     *
     * Generated from the refusals rather than hand-picked, so each one is a case: these are the values that pass the
     * validator's structural checks — they are well-formed decimals — and are then refused by the value objects that
     * own the rule. The validator deliberately does not duplicate those bounds; `NewInvoiceLineInput` explains why.
     *
     * @param callable(): NewInvoiceInput $payload
     */
    #[DataProvider('everyRefusalACallerCanFix')]
    public function testADomainRefusalBecomesAnUnprocessableEntity(callable $payload, string $expectedFragment): void
    {
        try {
            self::create($payload());
            self::fail('the domain must refuse this payload');
        } catch (UnprocessableEntityHttpException $refused) {
            self::assertStringContainsStringIgnoringCase($expectedFragment, $refused->getMessage());
            self::assertSame(
                422,
                $refused->getStatusCode(),
                'a caller-fixable refusal is 422 — a 500 would report the client\'s error as a server fault',
            );
        }
    }

    /** @return iterable<string, array{callable(): NewInvoiceInput, string}> */
    public static function everyRefusalACallerCanFix(): iterable
    {
        yield 'an unknown currency' => [
            static fn(): NewInvoiceInput => self::validInput(currency: 'XXQ'),
            // The domain's own wording: it refuses rather than assuming two decimals, because a wrong scale is a wrong
            // amount on a legal document — and TND has three.
            'is not in twes-in\'s ISO 4217 registry',
        ];
        yield 'a negative unit price' => [
            static fn(): NewInvoiceInput => self::inputWithLine('1', '-5.000', '19'),
            'is negative',
        ];
        yield 'a negative quantity' => [
            static fn(): NewInvoiceInput => self::inputWithLine('-2', '5.000', '19'),
            'is negative',
        ];
        yield 'a quantity with more decimals than a quantity may carry' => [
            static fn(): NewInvoiceInput => self::inputWithLine('1.1234567', '5.000', '19'),
            'decimal places',
        ];
        yield 'a negative VAT rate' => [
            static fn(): NewInvoiceInput => self::inputWithLine('1', '5.000', '-19'),
            'no jurisdiction has a negative VAT rate',
        ];
        yield 'a quantity whose product with the price cannot be represented' => [
            static fn(): NewInvoiceInput => self::inputWithLine('999999999999999', '2.000', '19'),
            'more integer digits than an amount can hold',
        ];
        yield 'a negative fixed charge' => [
            static fn(): NewInvoiceInput => self::validInput(charge: new NewFixedChargeInput('rebate', '-1.000')),
            'is negative',
        ];
        yield 'a fixed-charge label that is only whitespace' => [
            static fn(): NewInvoiceInput => self::validInput(charge: new NewFixedChargeInput('   ', '1.000')),
            'stable label',
        ];
    }

    /** ISSUING an unknown document is a 404 — indistinguishable from another tenant's, which is the design. */
    public function testIssuingAnUnknownInvoiceIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('No such invoice.');

        self::issue(new InMemoryInvoiceRepository(), self::FIRST_ID);
    }

    /** An ill-formed id is ALSO a 404, not a 400: telling a prober its guess had the right shape is a small oracle. */
    public function testAnIllFormedIdIsNotFoundRatherThanABadRequest(): void
    {
        $repository = new InMemoryInvoiceRepository();

        $this->expectException(NotFoundHttpException::class);

        self::issue($repository, 'NOT-A-UUID');
    }

    /** A non-string route value is a 404 too, not a `TypeError` reaching the client as a 500. */
    public function testANonStringIdIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        new IssueInvoiceProcessor(self::issueHandler(new InMemoryInvoiceRepository()))
            ->process(null, new Post(), ['id' => 42]);
    }

    /** Issuing an already-issued invoice is a 422: the transition guard refused, and the caller's page is stale. */
    public function testIssuingTwiceIsAnUnprocessableEntity(): void
    {
        $repository = new InMemoryInvoiceRepository();
        $created = self::createInto($repository, self::validInput());

        self::issue($repository, $created->id);

        $this->expectException(UnprocessableEntityHttpException::class);

        self::issue($repository, $created->id);
    }

    /** Issuing a document with no lines is a 422 as well, and its number is never spent. */
    public function testIssuingAnEmptyInvoiceIsAnUnprocessableEntity(): void
    {
        $repository = new InMemoryInvoiceRepository();
        $created = self::createInto($repository, new NewInvoiceInput('TND', [], []));

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/no lines/i');

        self::issue($repository, $created->id);
    }

    /**
     * A SUCCESSFUL ISSUE ANSWERS WITH THE ISSUED DOCUMENT, both halves of its number present.
     *
     * The status is 200 rather than 201 and that is declared on the operation, not here — nothing is created, a
     * document transitions. Asserted in `InvoiceWriteSurfaceTest`, where a real kernel produces a real response.
     */
    public function testIssuingAnswersWithTheIssuedDocument(): void
    {
        $repository = new InMemoryInvoiceRepository();
        $created = self::createInto($repository, self::validInput());

        $issued = self::issue($repository, $created->id);

        self::assertSame('issued', $issued->state);
        self::assertSame('0000001', $issued->number);
        self::assertSame(1, $issued->sequence);
        self::assertSame($created->id, $issued->id, 'the same document, transitioned');
    }

    /**
     * A PROCESSOR WIRED TO THE WRONG `input:` FAILS LOUDLY RATHER THAN SILENTLY.
     *
     * `ProcessorInterface::process()` takes `mixed`, so nothing in the type system connects the operation's `input:`
     * declaration to what this processor expects. A `\LogicException` because only a misconfigured operation reaches it
     * — which is exactly the sort of wiring mistake that would otherwise surface as a confusing `TypeError` deeper in.
     */
    public function testAProcessorGivenTheWrongInputTypeFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('`input:` is something else');

        new CreateInvoiceProcessor(self::createHandler(new InMemoryInvoiceRepository()))
            ->process(new \stdClass(), new Post());
    }

    // ------------------------------------------------------------------ fixtures

    private static function validInput(
        string $currency = 'TND',
        ?NewFixedChargeInput $charge = null,
    ): NewInvoiceInput {
        return new NewInvoiceInput(
            $currency,
            [
                new NewInvoiceLineInput('3', '1.234', '19'),
                new NewInvoiceLineInput('7', '0.567', '19'),
            ],
            [$charge ?? new NewFixedChargeInput('stamp_duty', '0.100')],
        );
    }

    private static function inputWithLine(string $quantity, string $unitNet, string $vatRate): NewInvoiceInput
    {
        return new NewInvoiceInput('TND', [new NewInvoiceLineInput($quantity, $unitNet, $vatRate)], []);
    }

    private static function create(NewInvoiceInput $input): \Twes\UI\Http\ApiResource\InvoiceResource
    {
        return self::createInto(new InMemoryInvoiceRepository(), $input);
    }

    private static function createInto(
        InMemoryInvoiceRepository $repository,
        NewInvoiceInput $input,
    ): \Twes\UI\Http\ApiResource\InvoiceResource {
        return new CreateInvoiceProcessor(self::createHandler($repository))->process($input, new Post());
    }

    private static function issue(
        InMemoryInvoiceRepository $repository,
        string $id,
    ): \Twes\UI\Http\ApiResource\InvoiceResource {
        return new IssueInvoiceProcessor(self::issueHandler($repository))->process(null, new Post(), ['id' => $id]);
    }

    private static function createHandler(InMemoryInvoiceRepository $repository): CreateInvoiceHandler
    {
        return new CreateInvoiceHandler($repository, new FixedIdGenerator(), new RecordingTransactionalScope());
    }

    private static function issueHandler(InMemoryInvoiceRepository $repository): IssueInvoiceHandler
    {
        return new IssueInvoiceHandler(
            $repository,
            new DocumentNumberAllocator(new InMemoryDocumentNumberSequence()),
            new RecordingTransactionalScope(),
            7,
        );
    }
}
