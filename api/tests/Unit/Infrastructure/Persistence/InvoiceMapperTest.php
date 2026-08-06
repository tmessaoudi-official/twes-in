<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Infrastructure\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Twes\Domain\Document\DocumentIdentity;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\DocumentNumber;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Infrastructure\Persistence\Doctrine\InvoiceMapper;
use Twes\Infrastructure\Tenancy\TenantId;

/**
 * THE ROUND-TRIP CONTRACT. This test is the price of the immutability ruling, and it is why that ruling was safe.
 *
 * `CLAUDE.md` § Architecture: the domain aggregate is `final readonly` with a private constructor and mutators that
 * `return new self(...)`, which Doctrine's identity map and change tracking cannot follow — so persistence uses a
 * SEPARATE mutable model and a mapper translates. That decision has one stated cost: *"a mapper per aggregate and a
 * real duplication risk if one is careless — paid down by a round-trip contract test, not by care."*
 *
 * This is that test. It exists to make the duplication risk MECHANICAL rather than a matter of attention: every
 * field must survive `toRows()` → `toAggregate()` unchanged, so a field added to the aggregate and forgotten in the
 * mapper fails here rather than silently reading back as null in production.
 *
 * **A structural equality assertion is deliberately NOT enough on its own**, and the per-field cases below are the
 * reason: `assertEquals` on two aggregates would pass if the mapper dropped a field the constructor defaults, and
 * it would give a diff nobody can read when it did fail. So the round trip is asserted field by field, and the
 * whole-object comparison is kept as the backstop that catches a field NO case thought to name.
 *
 * No database and no kernel: this is the `unit` suite, because a mapper is a pure function of its inputs. The
 * Doctrine repository that USES it needs a real PostgreSQL and belongs in `integration`.
 */
#[CoversClass(InvoiceMapper::class)]
final class InvoiceMapperTest extends TestCase
{
    private const COMPANY = '11111111-1111-4111-8111-111111111111';
    private const DOCUMENT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /**
     * @return iterable<string, array{Invoice, DocumentIdentity}>
     */
    public static function roundTrips(): iterable
    {
        $tnd = Currency::of('TND');
        $eur = Currency::of('EUR');

        yield 'an empty TND draft' => [
            Invoice::draft($tnd),
            self::identity(),
        ];

        // TND has THREE decimals (1 dinar = 1000 millimes), which is the default currency and therefore the case a
        // 2-decimal assumption breaks first. CLAUDE.md § Architecture calls that a bug for the default currency
        // rather than an edge case, so it leads here rather than appearing as an afterthought.
        yield 'a TND draft with a three-decimal unit price' => [
            Invoice::draft($tnd)->withLine(new DocumentLine('2', Money::of('0.100', $tnd), Rate::fromPercentage('19'))),
            self::identity(),
        ];

        yield 'multiple lines at multiple VAT rates' => [
            Invoice::draft($eur)
                ->withLine(new DocumentLine('1', Money::of('100.00', $eur), Rate::fromPercentage('20')))
                ->withLine(new DocumentLine('3.5', Money::of('12.34', $eur), Rate::fromPercentage('5.5')))
                // A ZERO rate is a real case (exempt supplies) and it is the one a falsy check would drop.
                ->withLine(new DocumentLine('1', Money::of('0.01', $eur), Rate::fromPercentage('0'))),
            self::identity(),
        ];

        // The finest amount TND can represent -- one millime. EUR cannot express it at all (`Money::of` refuses an
        // amount finer than the currency's scale, which is the guard working), so the three-decimal default currency
        // is the only place this boundary is reachable.
        yield 'the smallest representable TND amount, one millime' => [
            Invoice::draft($tnd)->withLine(new DocumentLine('1', Money::of('0.001', $tnd), Rate::fromPercentage('19'))),
            self::identity(),
        ];

        yield 'fixed charges alongside lines' => [
            Invoice::draft($tnd)
                ->withLine(new DocumentLine('1', Money::of('10.000', $tnd), Rate::fromPercentage('19')))
                ->withFixedCharge(new FixedCharge('stamp_duty', Money::of('0.100', $tnd)))
                ->withFixedCharge(new FixedCharge('delivery', Money::of('5.500', $tnd))),
            self::identity(),
        ];

        // ISSUED: the number appears, and the state moves. Both are persisted columns and both were null/draft in
        // every case above, so without this one the mapper could ignore them entirely and stay green.
        yield 'an issued invoice, carrying its number' => [
            Invoice::draft($tnd)
                ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
                ->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41)),
            self::identity(),
        ];

        // CANCELLED KEEPS ITS NUMBER -- `Invoice::number()` is documented as "kept forever afterwards, including
        // once cancelled". A mapper that reconstituted a cancelled document with a null number would satisfy every
        // other case here, and would destroy the audit trail a tax authority reads.
        yield 'a cancelled invoice, which keeps its number' => [
            Invoice::draft($tnd)
                ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
                ->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41))
                ->cancel(),
            self::identity(),
        ];

        yield 'the PerLine rounding point, which is per-document persisted configuration' => [
            Invoice::draft($tnd)->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19'))),
            self::identity(VatRoundingPoint::PerLine),
        ];
    }

    #[DataProvider('roundTrips')]
    public function testAnAggregateSurvivesTheRoundTripFieldByField(
        Invoice $invoice,
        DocumentIdentity $identity,
    ): void {
        $mapper = new InvoiceMapper();
        $tenant = TenantId::fromString(self::COMPANY);

        $rows = $mapper->toRows($tenant, $identity, $invoice);
        [$restoredIdentity, $restored] = $mapper->toAggregate($tenant, $rows);

        self::assertSame($invoice->currency()->code(), $restored->currency()->code(), 'currency');
        self::assertSame($invoice->state(), $restored->state(), 'state');
        self::assertSame($identity->type, $restoredIdentity->type, 'document type');
        self::assertSame($identity->vatRoundingPoint, $restoredIdentity->vatRoundingPoint, 'vat rounding point');
        self::assertSame($identity->id, $restoredIdentity->id, 'document id');

        self::assertSame(
            $invoice->number()?->sequence(),
            $restored->number()?->sequence(),
            'the document number sequence, which a cancelled document keeps forever',
        );

        self::assertCount(\count($invoice->lines()), $restored->lines(), 'line count');

        foreach ($invoice->lines() as $position => $line) {
            $restoredLine = $restored->lines()[$position];

            // QUANTITY IS COMPARED NUMERICALLY, and MONEY BY EXACT STRING. The asymmetry is deliberate and the
            // previous version of this had it wrong in a way that only a real database exposes.
            //
            // It used to `assertSame` the quantity string with a comment claiming that "pins scale". In this
            // in-memory test it passes; in production it is FALSE. `DocumentLine` stores the quantity verbatim, so
            // `'2'` stays `'2'`, and `NUMERIC(21,6)` returns `'2.000000'` — the same number, a different string.
            // Round 22 measured it. So the assertion pinned nothing and would have failed the moment a real
            // round-trip test existed, which is the worst kind of green.
            //
            // TWO LEVELS, and conflating them is what made the original comment wrong. IN MEMORY the mapper must be
            // an IDENTITY -- nothing re-scales, so `toRows()` → `toAggregate()` must return the same string, and the
            // whole-object backstop below enforces exactly that. A mutant re-scaling with `bcadd(…, 6)` is caught
            // there, correctly: that is the MAPPER renormalising, which it must never do. What is NOT stable is a
            // round trip through PostgreSQL, where `NUMERIC(21,6)` does the re-scaling.
            //
            // So `bccomp` here is deliberately the weaker of the two, and it is the right strength for THIS
            // assertion: it is the one the repository's integration test will reuse against a real column, where
            // insisting on the string would fail on correct code. The identity property is not lost — the backstop
            // holds it.
            //
            // Ruled 2026-08-01: quantity representation is NOT stable across a DATABASE round trip; only its VALUE
            // is.
            // Scale is semantically meaningful for MONEY — it is the currency's minor unit, and '0.100' TND is three
            // decimals by definition — and meaningless for a COUNT of things. Hence `bccomp` here and `assertSame`
            // on every `Money` amount below. The obligation this creates lives in Wave 4: the PDF renderer must
            // FORMAT quantity, never print the raw string, or an invoice reads "2.000000 ×".
            self::assertSame(
                0,
                bccomp($line->quantity(), $restoredLine->quantity(), DocumentLine::MAX_SCALE),
                "line {$position} quantity (numerically: representation is deliberately not pinned)",
            );
            self::assertSame(
                $line->unitNet()->amount(),
                $restoredLine->unitNet()->amount(),
                "line {$position} unit net amount",
            );
            self::assertSame(
                $line->unitNet()->currency()->code(),
                $restoredLine->unitNet()->currency()->code(),
                "line {$position} unit net currency",
            );
            self::assertSame(
                $line->vatRate()->fraction(),
                $restoredLine->vatRate()->fraction(),
                "line {$position} VAT rate",
            );
        }

        self::assertCount(\count($invoice->fixedCharges()), $restored->fixedCharges(), 'charge count');

        foreach ($invoice->fixedCharges() as $position => $charge) {
            $restoredCharge = $restored->fixedCharges()[$position];
            self::assertSame($charge->label(), $restoredCharge->label(), "charge {$position} label");
            self::assertSame(
                $charge->amount()->amount(),
                $restoredCharge->amount()->amount(),
                "charge {$position} amount",
            );
        }

        // THE BACKSTOP, and it is not redundant with the per-field assertions above: those name the fields somebody
        // thought of, and this one catches a field nobody did. When a field is added to the aggregate and forgotten
        // in the mapper, this fails even though no named case mentions it -- which is the whole reason the mapper
        // is allowed to exist.
        self::assertEquals($invoice, $restored, 'the whole aggregate, including any field no case above names');
    }

    /**
     * LINE ORDER IS PART OF THE CONTRACT, not an incidental property of however rows come back.
     *
     * `Invoice::withoutLine(int $position)` addresses lines BY POSITION and re-indexes to keep them contiguous, and
     * `CurrencyMismatch::inContext('document line %d')` reports a position to a client. So if a round trip can
     * reorder lines, "remove line 2" issued against a rehydrated document removes a DIFFERENT line -- which is
     * precisely the stale-page hazard `removeAt()` exists to prevent. `build-waves.plan.md` records the `position`
     * column as load-bearing for this reason; this asserts the mapper actually uses it.
     */
    public function testLineOrderSurvivesRowsArrivingOutOfOrder(): void
    {
        $eur = Currency::of('EUR');
        $mapper = new InvoiceMapper();
        // CHARGES TOO, and their absence was a real gap: this invoice held none, so `array_reverse($rows[2])`
        // reversed an EMPTY array and the charge `usort` was deletable with all nine tests green. Round 22 proved it
        // and showed the consequence -- `withoutFixedCharge(0)` removing `delivery` where the client saw
        // `stamp_duty`. The line half was pinned and the sibling collection was not, which is the full-set-coverage
        // rule in CLAUDE.md: a change applying to a CLASS of things must enumerate all of them.
        $invoice = Invoice::draft($eur)
            ->withLine(new DocumentLine('1', Money::of('1.00', $eur), Rate::fromPercentage('20')))
            ->withLine(new DocumentLine('2', Money::of('2.00', $eur), Rate::fromPercentage('20')))
            ->withLine(new DocumentLine('3', Money::of('3.00', $eur), Rate::fromPercentage('20')))
            ->withFixedCharge(new FixedCharge('first', Money::of('1.00', $eur)))
            ->withFixedCharge(new FixedCharge('second', Money::of('2.00', $eur)))
            ->withFixedCharge(new FixedCharge('third', Money::of('3.00', $eur)));

        $rows = $mapper->toRows(TenantId::fromString(self::COMPANY), self::identity(), $invoice);

        // A database returns rows in whatever order it likes without an ORDER BY, so the mapper must not depend on
        // the order it is handed. Reversing them is the cheapest way to prove it sorts by `position` rather than
        // trusting arrival order.
        $shuffled = [$rows[0], array_reverse($rows[1]), array_reverse($rows[2])];
        [, $restored] = $mapper->toAggregate(TenantId::fromString(self::COMPANY), $shuffled);

        self::assertSame(
            ['1', '2', '3'],
            array_map(static fn(DocumentLine $l): string => $l->quantity(), $restored->lines()),
            'lines must come back in their persisted position order, whatever order the rows arrived in',
        );

        self::assertSame(
            ['first', 'second', 'third'],
            array_map(static fn(FixedCharge $c): string => $c->label(), $restored->fixedCharges()),
            'charges must come back in position order too — withoutFixedCharge() addresses them by position',
        );
    }

    /**
     * `toRows()` must stamp the BOUND tenant on the document row AND on every child row.
     *
     * These are the three most security-critical lines in the mapper, and the round-trip contract test is
     * STRUCTURALLY blind to them: `toAggregate()` does not read `companyId`, so a round trip is a fixed point of any
     * transformation touching only write-only fields. Round 22 mutated all three to a foreign tenant and the whole
     * unit suite stayed green — 588 tests. No round-trip assertion can ever pin a write-only column; it needs a
     * direct assertion on the rows, which is what this is. `document_id` likewise: it is what a composite FK is
     * built on.
     */
    public function testToRowsStampsTheBoundTenantAndParentOnEveryRow(): void
    {
        $tnd = Currency::of('TND');
        $invoice = Invoice::draft($tnd)
            ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('2', Money::of('2.000', $tnd), Rate::fromPercentage('19')))
            ->withFixedCharge(new FixedCharge('stamp_duty', Money::of('0.100', $tnd)));

        [$document, $lines, $charges] = new InvoiceMapper()->toRows(
            TenantId::fromString(self::COMPANY),
            self::identity(),
            $invoice,
        );

        self::assertSame(self::COMPANY, $document->companyId->toRfc4122(), 'document row tenant');
        self::assertSame(self::DOCUMENT, $document->id->toRfc4122(), 'document row id');
        self::assertCount(2, $lines);
        self::assertCount(1, $charges);

        foreach ([...$lines, ...$charges] as $child) {
            self::assertSame(self::COMPANY, $child->companyId->toRfc4122(), 'child row tenant');
            self::assertSame(self::DOCUMENT, $child->documentId->toRfc4122(), 'child row parent id');
        }
    }

    /**
     * Hydration must REFUSE a child row belonging to another tenant rather than merging it.
     *
     * Round 22 built exactly this and got one `Invoice` carrying tenant B's line on tenant A's document — a wrong
     * legal document rather than a read leak, and no policy can catch it because the rows are already fetched.
     */
    public function testToAggregateRefusesAChildRowFromAnotherTenant(): void
    {
        $tnd = Currency::of('TND');
        $mapper = new InvoiceMapper();
        $tenant = TenantId::fromString(self::COMPANY);
        $invoice = Invoice::draft($tnd)
            ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')));

        [$document, $lines, $charges] = $mapper->toRows($tenant, self::identity(), $invoice);
        $lines[0]->companyId = Uuid::fromString('22222222-2222-4222-8222-222222222222');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/belongs to tenant/');

        $mapper->toAggregate($tenant, [$document, $lines, $charges]);
    }

    /**
     * An ISSUED document with NO LINES must be refused on hydration, exactly as `issue()` refuses to create one.
     *
     * `fromPersistedState()` guarded a missing number and not missing lines, and the stated rationale for the number
     * guard applies verbatim here: *"it fails at the boundary instead of surfacing later as an invoice with no number
     * on a PDF"*. This case is strictly worse — it surfaces as an invoice with **total 0.000** on a PDF.
     *
     * Reachable through the owed repository, whose docblock says a document is "rewritten whole on every save": a
     * delete-then-insert of line rows that half-commits, or a line query whose tenant binding differs from the
     * parent's (`set_config(..., true)` is transaction-local and `document_line` carries its own policy), leaves the
     * parent present and the children gone. Every later read is then a silent zero invoice.
     */
    public function testHydrationRefusesAnIssuedDocumentWithNoLines(): void
    {
        $tnd = Currency::of('TND');
        $mapper = new InvoiceMapper();
        $tenant = TenantId::fromString(self::COMPANY);
        $issued = Invoice::draft($tnd)
            ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
            ->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41));

        [$document, , $charges] = $mapper->toRows($tenant, self::identity(), $issued);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/no lines/');

        // The children lost, the parent kept -- the half-committed rewrite described above.
        $mapper->toAggregate($tenant, [$document, [], $charges]);
    }

    /**
     * A non-invoice type must be refused in BOTH directions.
     *
     * One table holds all four document types. Writing an `Invoice` under another type files its number in that
     * type's sequence, leaving a permanent hole in the gapless INVOICE sequence — what an audit reads as a
     * suppressed sale. Reading, it would pair an `Invoice` aggregate with a `Quote` identity from one call.
     */
    public function testTheMapperRefusesANonInvoiceTypeInBothDirections(): void
    {
        $tnd = Currency::of('TND');
        $mapper = new InvoiceMapper();
        $tenant = TenantId::fromString(self::COMPANY);
        $invoice = Invoice::draft($tnd);

        try {
            $mapper->toRows(
                $tenant,
                new DocumentIdentity(self::DOCUMENT, DocumentType::Quote, VatRoundingPoint::PerRateGroup),
                $invoice,
            );
            self::fail('toRows must refuse a non-invoice identity');
        } catch (\LogicException $refused) {
            self::assertStringContainsString('quote', $refused->getMessage());
        }

        $rows = $mapper->toRows($tenant, self::identity(), $invoice);
        $rows[0]->type = DocumentType::Quote->value;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/is a quote, not an invoice/');

        $mapper->toAggregate($tenant, $rows);
    }

    /**
     * THE RENDERED NUMBER IS PERSISTED, AND IT IS WHAT COMES BACK — ruled 2026-08-01.
     *
     * A document number is *(type, pattern, sequence)* and the pattern is per-tenant CONFIGURATION. Before this
     * column existed the mapper re-rendered a persisted sequence through a default width, so an administrator
     * widening the pattern from 7 to 8 turned an already-issued `0000041` into `00000041` — **a different number on
     * a legal document a client already holds**, and one this product promises to re-download byte-identically.
     *
     * The sequence stays the identity: `(company_id, type, number)` uniqueness and every ORDER BY are built on it,
     * and CLAUDE.md § Gotchas records that a gapless sequence is what a tax authority audits. The string is stored
     * BESIDE it, and the read path derives its pattern from the string rather than from configuration.
     */
    public function testTheRenderedNumberSurvivesTheRoundTripAsAString(): void
    {
        $tnd = Currency::of('TND');
        $mapper = new InvoiceMapper();
        $tenant = TenantId::fromString(self::COMPANY);

        $invoice = Invoice::draft($tnd)
            ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
            ->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41));

        $rows = $mapper->toRows($tenant, self::identity(), $invoice);

        // THE COLUMN IS ASSERTED DIRECTLY, not only through the round trip. `toRows()` writing the wrong string
        // while `toAggregate()` reads it back consistently is a fixed point of the round trip — the same structural
        // blindness `testToRowsStampsTheBoundTenantAndParentOnEveryRow()` exists for.
        self::assertSame('0000041', $rows[0]->numberRendered, 'the rendered string on the row');
        self::assertSame(41, $rows[0]->number, 'the raw sequence, which stays the identity');

        [, $restored] = $mapper->toAggregate($tenant, $rows);

        // NON-NULL ASSERTED ONCE, then plain `->`. Two chained `?->` calls is what PHPStan flagged
        // (`nullsafe.neverNull`): the first `assertSame` against a non-null string already narrows the receiver, so
        // the second nullsafe operator is dead — and a dead `?->` reads as though the value might be null, which is
        // exactly the state this test exists to rule out. `assertNotNull` says it instead of implying it.
        $number = $restored->number();
        self::assertNotNull($number, 'an issued document must come back numbered');

        self::assertSame('0000041', $number->number(), 'the rendered number after a round trip');
        self::assertSame('invoice 0000041', $number->toString(), 'the qualified form');
    }

    /**
     * THE READ PATH IGNORES CONFIGURATION ENTIRELY, which is the whole point of the column.
     *
     * This is the case that would have failed before the column existed: a document issued under one width, read
     * back while the tenant's configuration says another. The assertion is that the WIDTH IN THE STORED STRING wins
     * — nine digits stay nine digits — because the mapper has no pattern to consult any more. `InvoiceMapper` is now
     * stateless, and that is a stronger guarantee than a correct default: there is no configuration on the read path
     * to be wrong.
     */
    public function testAPersistedNumberRendersFromItsStoredStringAndNotFromAnyConfiguration(): void
    {
        $tnd = Currency::of('TND');
        $tenant = TenantId::fromString(self::COMPANY);

        $rows = new InvoiceMapper()->toRows(
            $tenant,
            self::identity(),
            Invoice::draft($tnd)
                ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
                ->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(9), 41)),
        );

        self::assertSame('000000041', $rows[0]->numberRendered);

        // A DIFFERENT mapper instance reads it — standing in for "a later request, after the setting changed".
        [, $restored] = new InvoiceMapper()->toAggregate($tenant, $rows);

        // Asserted through the RENDERING rather than through a `pattern()` accessor, and deliberately: adding a
        // getter to a domain value object so a test can look inside it widens the domain's public surface for test
        // convenience, which is the wrong direction. Nine characters back is the whole observable claim.
        $number = $restored->number();
        self::assertNotNull($number, 'an issued document must come back numbered');
        self::assertSame('000000041', $number->number());
    }

    /**
     * A SEQUENCE THAT OUTRAN ITS PADDING: the RENDERING round-trips, the WIDTH deliberately does not.
     *
     * `NumberPattern::format()` GROWS rather than truncating — truncating would produce a duplicate number, which
     * for a numbered legal document is the worst outcome available. So width 3 with sequence 12345 renders `12345`,
     * and the stored string carries no trace of the 3. [Verified: `padded(3)->format(12345)` and
     * `padded(5)->format(12345)` are both `12345`.]
     *
     * **That is accepted rather than worked around, and this test is where the acceptance is recorded.** Recovering
     * the 3 would need a second column, and the 2026-08-01 ruling rejected snapshotting the pattern per document as
     * *"equivalent guarantee, more indirection"*. It is genuinely equivalent here because the width has NO
     * observable effect once the sequence outruns it: every width from 1 to 5 renders this document identically, so
     * there is nothing a client, a PDF or an auditor could distinguish. The contract is the rendered document, not
     * the object graph — which is also why this case cannot use the whole-aggregate backstop the others rely on.
     */
    public function testASequenceThatOutranItsPaddingRoundTripsItsRenderingButNotItsWidth(): void
    {
        $tnd = Currency::of('TND');
        $tenant = TenantId::fromString(self::COMPANY);
        $mapper = new InvoiceMapper();

        $invoice = Invoice::draft($tnd)
            ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
            ->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(3), 12345));

        $rows = $mapper->toRows($tenant, self::identity(), $invoice);
        [, $restored] = $mapper->toAggregate($tenant, $rows);

        $authored = $invoice->number();
        $number = $restored->number();
        self::assertNotNull($authored, 'the fixture must be issued');
        self::assertNotNull($number, 'an issued document must come back numbered');

        self::assertSame('12345', $rows[0]->numberRendered, 'grown past the padding, as format() promises');
        self::assertSame(
            $authored->number(),
            $number->number(),
            'the RENDERED number is identical, which is the guarantee that matters',
        );
        // The width difference is visible as the LENGTH of what came back: five characters, from a pattern authored
        // at three. No `pattern()` accessor for the same reason as the case above.
        self::assertSame(5, \strlen($number->number()), 'the recovered width, deliberately not 3');
    }

    /** A draft has neither half, and the two must be absent together. */
    public function testADraftPersistsNeitherHalfOfTheNumber(): void
    {
        $tnd = Currency::of('TND');
        $rows = new InvoiceMapper()->toRows(
            TenantId::fromString(self::COMPANY),
            self::identity(),
            Invoice::draft($tnd)->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19'))),
        );

        self::assertNull($rows[0]->number, 'the sequence');
        self::assertNull($rows[0]->numberRendered, 'the rendered string');
    }

    /**
     * A ROW WITH A SEQUENCE AND NO RENDERED STRING IS REFUSED, rather than re-rendered from a guess.
     *
     * The database forbids this pairing (`document_number_halves_are_paired`), so reaching it means either a
     * migration ran without its constraint or our own layer built the row wrong — `error.internal` either way, per
     * CLAUDE.md § "Translation keys". **Re-rendering from a default is exactly the behaviour the ruling removed**,
     * so falling back to one here would reintroduce the defect at the only place still able to.
     */
    public function testASequenceWithNoRenderedStringIsRefused(): void
    {
        $tnd = Currency::of('TND');
        $tenant = TenantId::fromString(self::COMPANY);
        $mapper = new InvoiceMapper();

        $rows = $mapper->toRows(
            $tenant,
            self::identity(),
            Invoice::draft($tnd)
                ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
                ->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41)),
        );
        $rows[0]->numberRendered = null;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/carries sequence 41 with no rendered number/');

        $mapper->toAggregate($tenant, $rows);
    }

    /**
     * A STORED STRING THAT DISAGREES WITH ITS SEQUENCE IS REFUSED — the check that makes the derivation safe.
     *
     * The pattern is DERIVED from the stored string's length, and CLAUDE.md § Gotchas 2026-07-31 records the trap
     * that invites: *"a control may not derive its own expected value from the input it is validating."* Deriving
     * and then trusting would let `0000041` stored against sequence 99 read back as invoice 99 rendered `0000099`,
     * silently — a number nobody issued. So the re-render is compared against the stored string, which is what turns
     * a derivation into a checked round trip.
     */
    public function testARenderedStringThatDisagreesWithItsSequenceIsRefused(): void
    {
        $tnd = Currency::of('TND');
        $tenant = TenantId::fromString(self::COMPANY);
        $mapper = new InvoiceMapper();

        $rows = $mapper->toRows(
            $tenant,
            self::identity(),
            Invoice::draft($tnd)
                ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
                ->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41)),
        );
        // Same LENGTH as the correct rendering, so a length-only check would pass this.
        $rows[0]->numberRendered = '0000099';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/stored as "0000099".*sequence 41 renders as "0000041"/');

        $mapper->toAggregate($tenant, $rows);
    }

    private static function identity(
        VatRoundingPoint $vatRoundingPoint = VatRoundingPoint::PerRateGroup,
    ): DocumentIdentity {
        return new DocumentIdentity(
            self::DOCUMENT,
            DocumentType::Invoice,
            $vatRoundingPoint,
        );
    }
}
