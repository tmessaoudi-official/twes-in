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
        [$restoredIdentity, $restored] = $mapper->toAggregate($rows);

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

            // The DECIMAL STRING, not a float and not a loose comparison. `assertSame` on the string is what pins
            // scale: '0.100' and '0.1' are the same number and only one of them is TND's three decimals, so an
            // equality that accepted both would let the mapper silently renormalise a legal amount.
            self::assertSame($line->quantity(), $restoredLine->quantity(), "line {$position} quantity");
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
        $invoice = Invoice::draft($eur)
            ->withLine(new DocumentLine('1', Money::of('1.00', $eur), Rate::fromPercentage('20')))
            ->withLine(new DocumentLine('2', Money::of('2.00', $eur), Rate::fromPercentage('20')))
            ->withLine(new DocumentLine('3', Money::of('3.00', $eur), Rate::fromPercentage('20')));

        $rows = $mapper->toRows(TenantId::fromString(self::COMPANY), self::identity(), $invoice);

        // A database returns rows in whatever order it likes without an ORDER BY, so the mapper must not depend on
        // the order it is handed. Reversing them is the cheapest way to prove it sorts by `position` rather than
        // trusting arrival order.
        $shuffled = [$rows[0], array_reverse($rows[1]), array_reverse($rows[2])];
        [, $restored] = $mapper->toAggregate($shuffled);

        self::assertSame(
            ['1', '2', '3'],
            array_map(static fn(DocumentLine $l): string => $l->quantity(), $restored->lines()),
            'lines must come back in their persisted position order, whatever order the rows arrived in',
        );
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
