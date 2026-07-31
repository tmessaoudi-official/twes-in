<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Document\DocumentNumber;
use Twes\Domain\Document\DocumentState;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\Exception\IllegalTransition;
use Twes\Domain\Document\NumberPattern;

/**
 * The generic document lifecycle and numbering, both ruled in `pricing-and-documents.plan.md`.
 *
 * Generic from the start rather than invoice-specific with quotes and delivery notes bolted on — that is the
 * plan's wording, and Wave 2's whole theme is that anything true of Invoice must be true of Quote and Credit.
 * A lifecycle written for invoices first is the thing that makes Wave 2 a rewrite.
 */
#[CoversClass(DocumentState::class)]
#[CoversClass(DocumentNumber::class)]
#[CoversClass(NumberPattern::class)]
#[CoversClass(DocumentType::class)]
final class DocumentLifecycleTest extends TestCase
{
    // ------------------------------------------------------------------ the transition guard

    /**
     * The ONLY legal transitions are Draft -> Issued and Issued -> Cancelled.
     *
     * Enumerated exhaustively over the full cartesian product rather than case by case, because the
     * interesting half of a state machine is the transitions nobody wrote down. A hand-listed set of
     * "illegal" cases tests the ones the author thought of; this one fails the day a case is added to the
     * enum without a rule, which is exactly when a hole would otherwise open.
     */
    #[DataProvider('everyPossibleTransition')]
    public function testOnlyTheRuledTransitionsAreReachable(
        DocumentState $from,
        DocumentState $to,
        bool $legal,
    ): void {
        self::assertSame(
            $legal,
            $from->canTransitionTo($to),
            \sprintf('%s -> %s', $from->name, $to->name),
        );
    }

    /** @return iterable<string, array{DocumentState, DocumentState, bool}> */
    public static function everyPossibleTransition(): iterable
    {
        // Ruled in pricing-and-documents.plan.md: Draft (mutable, unnumbered) -> Issued (numbered,
        // immutable) and, for a correction, Issued -> Cancelled with a NEW document issued alongside.
        $legal = [
            'Draft->Issued' => true,
            'Issued->Cancelled' => true,
        ];

        foreach (DocumentState::cases() as $from) {
            foreach (DocumentState::cases() as $to) {
                $key = $from->name . '->' . $to->name;
                yield $key => [$from, $to, $legal[$key] ?? false];
            }
        }
    }

    /**
     * A state is never reached by ASSIGNMENT, only through the guard — CLAUDE.md § Architecture states this
     * as a P0 for `domain-correctness-reviewer`, and it is how illegal transitions enter a billing system.
     *
     * Asserted structurally: the enum exposes no setter and `transitionTo()` is the only way forward, so this
     * checks that the refusing path THROWS rather than returning a value a caller can ignore. A guard whose
     * result can be discarded is advice, not a guard.
     */
    #[DataProvider('everyPossibleTransition')]
    public function testAnIllegalTransitionThrowsRatherThanReturningFalse(
        DocumentState $from,
        DocumentState $to,
        bool $legal,
    ): void {
        if ($legal) {
            self::assertSame($to, $from->transitionTo($to));

            return;
        }

        try {
            $from->transitionTo($to);
            self::assertTrue(false, \sprintf('%s -> %s must throw', $from->name, $to->name));
        } catch (IllegalTransition $exception) {
            // The message must name BOTH states. A bare "illegal transition" in a log is unactionable, and
            // this exception will surface in a support ticket rather than in a debugger.
            self::assertStringContainsString($from->name, $exception->getMessage());
            self::assertStringContainsString($to->name, $exception->getMessage());
        }
    }

    /**
     * A document is mutable in exactly one state, and carries a number in exactly one.
     *
     * These two properties are what the whole lifecycle exists to enforce — "an issued document is immutable"
     * and "corrections go by cancel-and-reissue" — so they are asserted over every case rather than inferred
     * from the transition table.
     */
    #[DataProvider('everyState')]
    public function testMutabilityAndNumberingFollowTheState(DocumentState $state): void
    {
        self::assertSame(
            DocumentState::Draft === $state,
            $state->isMutable(),
            'Only a draft is mutable: an issued document may already be in a client\'s hands, and a '
            . 'cancelled one is an audit record.',
        );

        self::assertSame(
            DocumentState::Draft !== $state,
            $state->requiresNumber(),
            'A draft is unnumbered; everything past it carries its number forever, including a cancelled '
            . 'document — which stays on file precisely so the number is never silently reused.',
        );
    }

    /** @return iterable<string, array{DocumentState}> */
    public static function everyState(): iterable
    {
        foreach (DocumentState::cases() as $state) {
            yield $state->name => [$state];
        }
    }

    /**
     * A cancelled document is TERMINAL — it is never revived, and its number is never reused.
     *
     * The plan rules cancel-and-reissue: the wrong document "stays on file; a new one is issued". Reviving a
     * cancelled document would break the byte-identical-re-download guarantee, because a client may already
     * hold the PDF that was cancelled.
     */
    public function testACancelledDocumentIsTerminal(): void
    {
        foreach (DocumentState::cases() as $to) {
            self::assertFalse(
                DocumentState::Cancelled->canTransitionTo($to),
                'Cancelled is terminal; a correction is a NEW document.',
            );
        }
    }

    // ------------------------------------------------------------------ numbering

    /**
     * A number is zero-padded from a configurable pattern and carries NO type marker.
     *
     * Developer ruling, 2026-07-29: no `DN-` prefix, no `INV-`. The document's title and template carry its
     * identity; the number is a plain padded integer from the same generic machinery for every type.
     */
    public function testANumberIsPaddedFromTheConfiguredPatternWithNoTypeMarker(): void
    {
        $pattern = NumberPattern::padded(7);

        self::assertSame('0000041', $pattern->format(41));
        self::assertSame('0000001', $pattern->format(1));
        self::assertSame('9999999', $pattern->format(9999999));

        // No prefix, no separator, no letters — asserted rather than assumed, because a prefix is the
        // "helpful" thing a later contributor adds and it was ruled out explicitly.
        self::assertMatchesRegularExpression('/^\d+$/', $pattern->format(41));
    }

    /**
     * A number WIDER than the pattern is not truncated — it grows.
     *
     * Truncating would produce a DUPLICATE number, which for a numbered legal document is the worst
     * available outcome: two invoices with one identity, and a tax authority that cannot tell them apart.
     * Growing breaks a column width at worst.
     */
    public function testANumberWiderThanThePatternGrowsRatherThanTruncating(): void
    {
        self::assertSame('12345678', NumberPattern::padded(7)->format(12345678));
    }

    /**
     * THE CONSEQUENCE THE PLAN SAYS TO ACCEPT, made impossible instead.
     *
     * Sequences are per type, so invoice `0000041` and delivery note `0000041` both legitimately exist. The
     * plan's wording: "any internal reference, search result or API payload must name the document type
     * alongside the number, never the number alone." A rule stated that way relies on every future caller
     * remembering it — and this repository has recorded four times that a control enforced only by memory is
     * not a control.
     *
     * So `DocumentNumber` carries its type and there is no way to construct a bare one. Two numbers with the
     * same digits and different types are NOT equal, and the string form names the type.
     */
    public function testTwoDocumentsSharingDigitsAcrossTypesAreNotTheSameDocument(): void
    {
        $invoice = new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41);
        $deliveryNote = new DocumentNumber(DocumentType::DeliveryNote, NumberPattern::padded(7), 41);

        self::assertSame('0000041', $invoice->number());
        self::assertSame('0000041', $deliveryNote->number());

        self::assertFalse(
            $invoice->equals($deliveryNote),
            'Same digits, different documents. Equality on the digits alone is how a delivery note gets '
            . 'paid against an invoice.',
        );
        self::assertTrue($invoice->equals(
            new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41),
        ));

        // The printable identity names the type, so a reference copied out of a log or an API payload is
        // never ambiguous — which is the stated requirement, now structural.
        self::assertStringContainsString('0000041', $invoice->toString());
        self::assertNotSame($invoice->toString(), $deliveryNote->toString());
    }

    /**
     * A sequence starts at 1 and a non-positive number is refused.
     *
     * Zero is the value an uninitialised counter has, so accepting it would mean the first document of a
     * tenant's life silently gets number `0000000` — and the failure would be invisible until an accountant
     * asked why the sequence starts at zero.
     */
    #[DataProvider('invalidSequenceNumbers')]
    public function testANonPositiveSequenceNumberIsRefused(int $sequence): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), $sequence);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidSequenceNumbers(): iterable
    {
        yield 'zero, the uninitialised-counter value' => [0];
        yield 'negative' => [-1];
    }

    /**
     * A pattern width must be sane, and the boundary is asserted in both directions.
     */
    public function testAPatternWidthMustBePositive(): void
    {
        self::assertSame('1', NumberPattern::padded(1)->format(1), 'width 1 is legal');

        $this->expectException(\InvalidArgumentException::class);
        NumberPattern::padded(0);
    }

    /**
     * EVERY document type uses the SAME numbering machinery.
     *
     * Ruled explicitly — "using the same generic numbering machinery rather than a delivery-note-specific
     * one". Asserted over every case of the enum, so a type added in Wave 2 cannot arrive with its own
     * private numbering scheme without failing here.
     */
    #[DataProvider('everyDocumentType')]
    public function testEveryDocumentTypeNumbersThroughTheSameMachinery(DocumentType $type): void
    {
        $number = new DocumentNumber($type, NumberPattern::padded(7), 41);

        self::assertSame('0000041', $number->number(), $type->name . ' must use the shared pattern');
        self::assertSame($type, $number->type());
    }

    /** @return iterable<string, array{DocumentType}> */
    public static function everyDocumentType(): iterable
    {
        foreach (DocumentType::cases() as $type) {
            yield $type->name => [$type];
        }
    }
    /**
     * `NumberPattern::format()` refuses a non-positive sequence itself.
     *
     * It is public, and it rendered `format(0)` as `"0000000"` — exactly the string `DocumentNumber` refuses a
     * sequence of zero to prevent — and `format(-5)` as `"00000-5"`, a legal-looking number with a minus inside
     * it. A value object that refuses a state while a collaborator renders it on request has not refused it.
     *
     */
    #[DataProvider('nonPositiveSequences')]
    public function testFormatRefusesANonPositiveSequence(int $sequence): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NumberPattern::padded(7)->format($sequence);
    }

    /** @return iterable<string, array{int}> */
    public static function nonPositiveSequences(): iterable
    {
        yield 'zero, which rendered as 0000000' => [0];
        yield 'negative, which rendered as 00000-5' => [-5];
    }

    /**
     * The enum VALUES are pinned, because they are wire format and a persisted column.
     *
     * `DocumentNumber::toString()` is documented as being for "a log line, a search result, an API payload", so
     * these strings reach consumers. CLAUDE.md § "The API contract is ours to design" makes an enum-value change
     * a breaking change with a migration plan, never an incidental edit — and a non-backed enum would put a PHP
     * case NAME on the wire, making a pure refactor a breaking change. Pinned here so that renaming a case
     * without deciding to break the contract fails loudly.
     */
    public function testTheEnumValuesArePinnedBecauseTheyAreWireFormat(): void
    {
        self::assertSame(
            ['invoice', 'quote', 'credit', 'delivery_note'],
            array_map(static fn(DocumentType $t): string => $t->value, DocumentType::cases()),
        );
        self::assertSame(
            ['draft', 'issued', 'cancelled'],
            array_map(static fn(DocumentState $s): string => $s->value, DocumentState::cases()),
        );

        // And the printable identity uses the backed VALUE, not the case name.
        self::assertSame(
            'delivery_note 0000041',
            new DocumentNumber(DocumentType::DeliveryNote, NumberPattern::padded(7), 41)->toString(),
        );
    }

}
