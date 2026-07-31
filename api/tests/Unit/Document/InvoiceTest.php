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
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\DocumentNumber;
use Twes\Domain\Document\DocumentState;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\Exception\DocumentIsNotMutable;
use Twes\Domain\Document\Exception\IllegalTransition;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\RoundingMode;

/**
 * The invoice aggregate: the lifecycle, the numbering and the calculation kernel, composed.
 *
 * This class owns **when** things may change and **what an issued document guarantees**. It owns no
 * arithmetic — every figure comes from `DocumentCalculator`, which is itself driven by the shared cross-tier
 * vectors — because "one implementation, never two" is an architecture invariant and a second copy of a VAT
 * formula is how two tiers of one product come to disagree about tax owed.
 */
#[CoversClass(Invoice::class)]
final class InvoiceTest extends TestCase
{
    private const string TENANT_TND = 'TND';

    // ------------------------------------------------------------------ the draft

    public function testANewInvoiceIsAMutableUnnumberedDraft(): void
    {
        $invoice = self::draft();

        self::assertSame(DocumentState::Draft, $invoice->state());
        self::assertTrue($invoice->state()->isMutable());
        self::assertNull($invoice->number(), 'A draft carries no number — numbers come from issuing.');
    }

    public function testADraftAccumulatesLinesAndItsTotalsFollow(): void
    {
        $tnd = Currency::of(self::TENANT_TND);
        $invoice = self::draft()
            ->withLine(new DocumentLine('10', Money::of('12.000', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('5', Money::of('12.500', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('2', Money::of('85.000', $tnd), Rate::fromPercentage('19')));

        // The worked consolidation from the spec, and the figures are the SHARED VECTORS' own — this is the
        // `consolidated-invoice-with-tunisian-stamp-duty` case minus its stamp duty, so the aggregate is
        // proven to route through the same kernel rather than to have its own arithmetic.
        $totals = $invoice->totals(VatRoundingPoint::PerRateGroup, RoundingMode::HalfUp);

        self::assertSame('352.500', $totals->subtotalNet()->amount());
        self::assertSame('66.975', $totals->vatTotal()->amount());
        self::assertSame('419.475', $totals->total()->amount());
    }

    public function testAFixedChargeReachesTheTotalAndNoVatBase(): void
    {
        $tnd = Currency::of(self::TENANT_TND);
        $invoice = self::draft()
            ->withLine(new DocumentLine('10', Money::of('12.000', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('5', Money::of('12.500', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('2', Money::of('85.000', $tnd), Rate::fromPercentage('19')))
            ->withFixedCharge(new FixedCharge('stamp_duty', Money::of('0.100', $tnd)));

        $totals = $invoice->totals(VatRoundingPoint::PerRateGroup, RoundingMode::HalfUp);

        // Now the full shared vector, stamp duty included: 419.575, not 419.475.
        self::assertSame('66.975', $totals->vatTotal()->amount(), 'the charge must not enter a VAT base');
        self::assertSame('419.575', $totals->total()->amount());
    }

    /**
     * Every mutation returns a NEW invoice; the original is untouched.
     *
     * Immutability is not a style preference here. An issued document's snapshot must be impossible to move by
     * a later edit, and the cheapest way to guarantee that is for no instance ever to change.
     */
    public function testEveryMutationReturnsANewInstanceAndLeavesTheOriginalAlone(): void
    {
        $tnd = Currency::of(self::TENANT_TND);
        $empty = self::draft();
        $withLine = $empty->withLine(new DocumentLine('1', Money::of('10.000', $tnd), Rate::zero()));

        self::assertNotSame($empty, $withLine);
        self::assertCount(0, $empty->lines(), 'the original must not have gained a line');
        self::assertCount(1, $withLine->lines());
    }

    // ------------------------------------------------------------------ issuing

    public function testIssuingAssignsTheNumberAndFreezesTheDocument(): void
    {
        $tnd = Currency::of(self::TENANT_TND);
        $number = new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41);

        $issued = self::draft()
            ->withLine(new DocumentLine('1', Money::of('10.000', $tnd), Rate::fromPercentage('19')))
            ->issue($number);

        self::assertSame(DocumentState::Issued, $issued->state());
        self::assertFalse($issued->state()->isMutable());
        self::assertNotNull($issued->number());
        self::assertSame('0000041', $issued->number()->number());
        self::assertSame(DocumentType::Invoice, $issued->number()->type());
    }

    /**
     * An ISSUED invoice refuses EVERY mutation — as a set, not one method at a time.
     *
     * A guard on one of a class of entry points is the defect this repository records more often than any
     * other, so the mutating surface is enumerated and driven rather than spot-checked. If a fifth mutator is
     * added without a guard, `testTheMutatorInventoryIsComplete` below fails.
     *
     * @param callable(Invoice): Invoice $mutation
     */
    #[DataProvider('everyMutation')]
    public function testAnIssuedInvoiceRefusesEveryMutation(string $name, callable $mutation): void
    {
        $issued = self::issuedDraft();

        $this->expectException(DocumentIsNotMutable::class);
        $this->expectExceptionMessage($name);

        $mutation($issued);
    }

    /**
     * And a CANCELLED invoice refuses them too — it is an audit record, not a document.
     *
     * @param callable(Invoice): Invoice $mutation
     */
    #[DataProvider('everyMutation')]
    public function testACancelledInvoiceRefusesEveryMutation(string $name, callable $mutation): void
    {
        $cancelled = self::issuedDraft()->cancel();

        $this->expectException(DocumentIsNotMutable::class);

        $mutation($cancelled);
    }

    /** @return iterable<string, array{string, callable}> */
    public static function everyMutation(): iterable
    {
        $tnd = Currency::of(self::TENANT_TND);

        yield 'withLine' => [
            'withLine',
            static fn(Invoice $i): Invoice => $i->withLine(
                new DocumentLine('1', Money::of('1.000', $tnd), Rate::zero()),
            ),
        ];
        yield 'withoutLine' => ['withoutLine', static fn(Invoice $i): Invoice => $i->withoutLine(0)];
        yield 'withFixedCharge' => [
            'withFixedCharge',
            static fn(Invoice $i): Invoice => $i->withFixedCharge(
                new FixedCharge('x', Money::of('1.000', $tnd)),
            ),
        ];
        yield 'withoutFixedCharge' => [
            'withoutFixedCharge',
            static fn(Invoice $i): Invoice => $i->withoutFixedCharge(0),
        ];
    }

    /**
     * The mutator inventory is derived from the class, not written down.
     *
     * A hand-listed provider covers the mutators somebody thought of. This fails the day a fifth `with*`
     * mutator lands without a case, which is exactly when the guard above would otherwise silently cover four
     * of five — the shape `test-gates.sh` had to be taught twice.
     */
    public function testTheMutatorInventoryIsComplete(): void
    {
        $covered = array_keys(iterator_to_array(self::everyMutation()));
        $mutators = [];

        foreach (new \ReflectionClass(Invoice::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // A mutator is a public method returning `self` whose name begins `with`. `issue` and `cancel`
            // also return self but are TRANSITIONS, guarded by DocumentState rather than by mutability, and
            // they are tested separately below.
            // Compared against the RESOLVED class name, not the literal `self`: PHP's reflection resolves a
            // `: self` return type to the declaring class, so matching on `'self'` finds nothing — and the
            // assertion below fired on its own author, which is the argument for having it.
            if (str_starts_with($method->getName(), 'with')
                && Invoice::class === (string) $method->getReturnType()) {
                $mutators[] = $method->getName();
            }
        }

        self::assertNotEmpty($mutators, 'Reflection found no mutator, so the provider above is vacuous.');
        sort($covered);
        sort($mutators);
        self::assertSame($mutators, $covered);
    }

    /**
     * Issuing an EMPTY invoice is refused.
     *
     * **A derived decision, not a ruled one, and flagged as such.** Nothing in the plans says whether an empty
     * invoice may be issued. It is refused because issuing consumes a number from a per-tenant sequence
     * *permanently*: numbers are never reused, and a cancelled document stays on file forever precisely so its
     * number is not recycled. So issuing an empty invoice burns a legal document number on a document with no
     * content, unrecoverably. If the developer rules otherwise this is one line, and the reasoning is recorded
     * rather than the behaviour merely asserted.
     */
    public function testIssuingAnEmptyInvoiceIsRefused(): void
    {
        $number = new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 1);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/no lines|empty/i');

        self::draft()->issue($number);
    }

    public function testIssuingTwiceIsRefused(): void
    {
        $issued = self::issuedDraft();

        $this->expectException(IllegalTransition::class);

        $issued->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 42));
    }

    public function testAnInvoiceNumberedAsAnotherTypeIsRefused(): void
    {
        $tnd = Currency::of(self::TENANT_TND);
        $draft = self::draft()->withLine(
            new DocumentLine('1', Money::of('10.000', $tnd), Rate::zero()),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/DeliveryNote|type/i');

        // Sequences are per type and the digits alone are ambiguous — which is why DocumentNumber carries its
        // type. An invoice must refuse a delivery note's number outright rather than storing it.
        $draft->issue(new DocumentNumber(DocumentType::DeliveryNote, NumberPattern::padded(7), 41));
    }

    /**
     * Removing a position that does not exist THROWS rather than being a silent no-op.
     *
     * The justification was written into `Invoice::removeAt()` and then tested by nothing — a mutant replacing
     * the bounds check with `if (false)` passed the whole suite. That is the third time this session an arm has
     * been argued for in a comment and exercised by no case, so it is asserted for BOTH collections rather
     * than for the one that happened to come to mind.
     *
     * Why it matters, restated from the code: a no-op means a user clicked "remove" on a stale page, the row
     * stayed, and the document they then issued contains a line they believe they deleted — a wrong legal
     * document produced by a UI race, with no error anywhere.
     *
     * @param callable(Invoice): Invoice $removal
     */
    #[DataProvider('everyOutOfRangeRemoval')]
    public function testRemovingAPositionThatDoesNotExistThrows(string $what, callable $removal): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage($what);

        $removal(self::draftWithOneOfEach());
    }

    /** @return iterable<string, array{string, callable}> */
    public static function everyOutOfRangeRemoval(): iterable
    {
        // Just past the end, and negative — the two shapes an off-by-one produces. `array_key_exists` handles
        // both, which is why it is used rather than a `< count()` comparison that would accept -1.
        yield 'line, past the end' => ['line', static fn(Invoice $i): Invoice => $i->withoutLine(1)];
        yield 'line, negative' => ['line', static fn(Invoice $i): Invoice => $i->withoutLine(-1)];
        yield 'charge, past the end' => [
            'fixed charge',
            static fn(Invoice $i): Invoice => $i->withoutFixedCharge(1),
        ];
        yield 'charge, negative' => [
            'fixed charge',
            static fn(Invoice $i): Invoice => $i->withoutFixedCharge(-1),
        ];
    }

    /**
     * A successful removal RE-INDEXES, so a later removal by position means what the caller sees.
     *
     * Without `array_values()` the surviving keys would be sparse, and `withoutLine(1)` after removing index 0
     * would then throw for a document that visibly has two lines left.
     */
    public function testRemovingALineReIndexesTheRemainder(): void
    {
        $tnd = Currency::of(self::TENANT_TND);
        $three = self::draft()
            ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::zero()))
            ->withLine(new DocumentLine('2', Money::of('2.000', $tnd), Rate::zero()))
            ->withLine(new DocumentLine('3', Money::of('3.000', $tnd), Rate::zero()));

        $remaining = $three->withoutLine(0);

        self::assertCount(2, $remaining->lines());
        self::assertSame([0, 1], array_keys($remaining->lines()), 'positions must stay contiguous');
        self::assertSame('2', $remaining->lines()[0]->quantity(), 'the second line moved to position 0');

        // And removing the new last position succeeds rather than throwing, which is what re-indexing buys.
        self::assertCount(1, $remaining->withoutLine(1)->lines());
    }

    // ------------------------------------------------------------------ cancelling

    public function testCancellingKeepsTheNumberSoItIsNeverReused(): void
    {
        $issued = self::issuedDraft();
        $cancelled = $issued->cancel();

        self::assertSame(DocumentState::Cancelled, $cancelled->state());
        self::assertNotNull($cancelled->number());
        self::assertTrue(
            $cancelled->number()->equals($issued->number()),
            'A cancelled document keeps its number on file — that is what stops the number being recycled.',
        );
    }

    public function testCancellingADraftIsRefusedBecauseThereIsNothingToCancel(): void
    {
        $this->expectException(IllegalTransition::class);

        self::draft()->cancel();
    }

    public function testCancellingTwiceIsRefused(): void
    {
        $cancelled = self::issuedDraft()->cancel();

        $this->expectException(IllegalTransition::class);

        $cancelled->cancel();
    }

    /**
     * A cancelled invoice's FIGURES are unchanged — it is the audit record of what was issued.
     *
     * Asserted because "cancelled" must not mean "zeroed": the correction is a new document, and this one
     * stays on file stating exactly what the client was originally sent.
     */
    public function testACancelledInvoiceKeepsTheFiguresItWasIssuedWith(): void
    {
        $issued = self::issuedDraft();
        $before = $issued->totals(VatRoundingPoint::PerRateGroup, RoundingMode::HalfUp);
        $after = $issued->cancel()->totals(VatRoundingPoint::PerRateGroup, RoundingMode::HalfUp);

        self::assertSame($before->total()->amount(), $after->total()->amount());
        self::assertSame($before->vatTotal()->amount(), $after->vatTotal()->amount());
    }

    // ------------------------------------------------------------------ currency

    public function testAnInvoiceRefusesALineInAnotherCurrency(): void
    {
        $this->expectException(\Twes\Domain\Money\Exception\CurrencyMismatch::class);

        self::draft()->withLine(
            new DocumentLine('1', Money::of('1.00', Currency::of('EUR')), Rate::zero()),
        );
    }

    public function testAnEmptyDraftKnowsItsCurrencyBeforeAnyLineExists(): void
    {
        // The currency is the DOCUMENT's, fixed at creation — not inferred from the first line. An invoice
        // being drafted in EUR is an EUR invoice before anything is typed into it, and the UI needs to know.
        self::assertSame(
            'TND',
            self::draft()->currency()->code(),
            'A draft must know its currency with zero lines, or a new invoice has no scale to format with.',
        );
    }

    // ------------------------------------------------------------------ helpers

    private static function draft(): Invoice
    {
        return Invoice::draft(Currency::of(self::TENANT_TND));
    }

    private static function draftWithOneOfEach(): Invoice
    {
        $tnd = Currency::of(self::TENANT_TND);

        return self::draft()
            ->withLine(new DocumentLine('1', Money::of('10.000', $tnd), Rate::zero()))
            ->withFixedCharge(new FixedCharge('stamp_duty', Money::of('0.100', $tnd)));
    }

    private static function issuedDraft(): Invoice
    {
        $tnd = Currency::of(self::TENANT_TND);

        return self::draft()
            ->withLine(new DocumentLine('2', Money::of('10.000', $tnd), Rate::fromPercentage('19')))
            ->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41));
    }
}
