<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine;

use Symfony\Component\Uid\Uuid;
use Twes\Domain\Document\DocumentIdentity;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\DocumentNumber;
use Twes\Domain\Document\DocumentState;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Infrastructure\Persistence\Doctrine\Entity\DocumentChargeRow;
use Twes\Infrastructure\Persistence\Doctrine\Entity\DocumentLineRow;
use Twes\Infrastructure\Persistence\Doctrine\Entity\DocumentRow;
use Twes\Infrastructure\Tenancy\TenantId;

/**
 * `Invoice` + `DocumentIdentity` ⇄ the mutable Doctrine rows. The translation the immutability ruling requires.
 *
 * **This class is the accepted COST of a decision, not a convenience.** `CLAUDE.md` § Architecture: the aggregate is
 * `final readonly` with mutators that `return new self(...)`, and Doctrine's unit of work is an identity map holding
 * ONE mutable instance per row, diffed against a snapshot to emit UPDATEs. Mapping the aggregate directly is
 * insert-only and fights the ORM under either driver — so the mapping lives on a separate model and this translates.
 * The stated price was *"a mapper per aggregate and a real duplication risk if one is careless — paid down by a
 * round-trip contract test, not by care"*, and `InvoiceMapperTest` is that test. Read it before changing anything
 * here: it asserts field by field, and its whole-object backstop is what catches a field nobody named.
 *
 * **A NUMBER PATTERN IS A CONSTRUCTOR DEPENDENCY, AND THAT IS THE UNCOMFORTABLE PART.** `DocumentNumber` is
 * *(type, pattern, sequence)*. The row stores type and sequence; the PATTERN is per-tenant configuration, on a
 * settings table Wave 1 has not built. So reconstituting a number needs a pattern from somewhere, and until that
 * table exists it comes from here as a default.
 *
 * That is a REAL HAZARD rather than a placeholder, and it was filed as one by certification round 21: an
 * administrator widening the width from 7 to 8 re-renders an already-issued invoice as `00000041` instead of
 * `0000041` — a different number on a legal document a client already holds. `build-waves.plan.md` accepts the trade
 * explicitly (*"the pattern is per-tenant configuration and may change: NumberPattern renders, it does not
 * identify"*) while applying the OPPOSITE principle three rows above to `vat_rounding_point` (*"a company changing
 * it must not restate a document a client holds"*). Those two cannot both be right, and resolving it is a product
 * decision owed to the developer — it belongs to whichever wave writes the settings table. Recorded here, at the
 * line of code that would have to change, rather than only in a plan.
 */
final readonly class InvoiceMapper
{
    /**
     * @param NumberPattern $numberPattern used ONLY to re-render a persisted sequence. See the class docblock: this
     *                                     is per-tenant configuration standing in for a settings table that does not
     *                                     exist yet, and changing it changes how already-issued numbers RENDER.
     */
    private NumberPattern $numberPattern;

    public function __construct(?NumberPattern $numberPattern = null)
    {
        // Resolved in the body, not as a parameter default: `NumberPattern`'s constructor is private -- `padded()`
        // is the factory, and it enforces the 1..MAX_WIDTH bounds that a raw `new` would bypass. PHP permits `new`
        // in an initialiser but not a static call, so a nullable parameter is the only shape that keeps the
        // validated factory in the path.
        $this->numberPattern = $numberPattern ?? NumberPattern::padded(7);
    }

    /**
     * @return array{DocumentRow, list<DocumentLineRow>, list<DocumentChargeRow>}
     */
    public function toRows(TenantId $tenant, DocumentIdentity $identity, Invoice $invoice): array
    {
        $document = new DocumentRow();
        $document->companyId = Uuid::fromString($tenant->toString());
        $document->id = Uuid::fromString($identity->id);
        $document->type = $identity->type->value;
        $document->state = $invoice->state()->value;
        $document->currency = $invoice->currency()->code();
        // The RAW SEQUENCE, never the rendered string. `NumberPattern` renders; it does not identify — so persisting
        // `0000041` would bake today's pattern into the row and make the column unusable for ordering or uniqueness.
        $document->number = $invoice->number()?->sequence();
        $document->vatRoundingPoint = $identity->vatRoundingPoint->value;

        $lines = [];

        foreach ($invoice->lines() as $position => $line) {
            $row = new DocumentLineRow();
            $row->companyId = Uuid::fromString($tenant->toString());
            $row->documentId = Uuid::fromString($identity->id);
            // The ARRAY INDEX is the position, and `Invoice` guarantees it is contiguous: `withoutLine()` re-indexes
            // through `removeAt()` precisely so positions stay dense. Persisting the index rather than a counter is
            // what makes "remove line 2" mean the same thing before and after a round trip.
            $row->position = $position;
            $row->quantity = $line->quantity();
            $row->unitNet = $line->unitNet()->amount();
            // The FRACTION, not the percentage: `Rate::fraction()` is the exact stored form, and `NUMERIC(27,12)` is
            // sized from `FRACTION_SCALE` (12) + `MAX_INTEGER_DIGITS` (15). Persisting the percentage would divide by
            // 100 on the way back in and lose the twelfth decimal on a rate like 5.5%.
            $row->vatRate = $line->vatRate()->fraction();
            $lines[] = $row;
        }

        $charges = [];

        foreach ($invoice->fixedCharges() as $position => $charge) {
            $row = new DocumentChargeRow();
            $row->companyId = Uuid::fromString($tenant->toString());
            $row->documentId = Uuid::fromString($identity->id);
            $row->position = $position;
            $row->label = $charge->label();
            $row->amount = $charge->amount()->amount();
            $charges[] = $row;
        }

        return [$document, $lines, $charges];
    }

    /**
     * @param array{DocumentRow, list<DocumentLineRow>, list<DocumentChargeRow>} $rows
     *
     * @return array{DocumentIdentity, Invoice}
     */
    public function toAggregate(array $rows): array
    {
        [$document, $lineRows, $chargeRows] = $rows;

        $currency = Currency::of($document->currency);
        $type = DocumentType::from($document->type);
        $state = DocumentState::from($document->state);

        /*
         * SORTED BY POSITION, never trusted in arrival order.
         *
         * A SELECT without ORDER BY returns rows in whatever order the server finds convenient, and that order can
         * change with a plan, a vacuum or a version. `Invoice::withoutLine(int $position)` addresses lines BY
         * POSITION, so a reordering round trip means "remove line 2" removes a DIFFERENT line than the client saw --
         * exactly the stale-page hazard `removeAt()` exists to prevent. Sorting here rather than relying on the
         * repository's ORDER BY is deliberate belt-and-braces: this class is the one that promises order, so it is
         * the one that must not depend on somebody else remembering.
         */
        usort($lineRows, static fn(DocumentLineRow $a, DocumentLineRow $b): int => $a->position <=> $b->position);
        usort($chargeRows, static fn(DocumentChargeRow $a, DocumentChargeRow $b): int => $a->position <=> $b->position);

        $lines = array_map(
            static fn(DocumentLineRow $row): DocumentLine => new DocumentLine(
                $row->quantity,
                Money::of($row->unitNet, $currency),
                Rate::fromFraction($row->vatRate),
            ),
            $lineRows,
        );

        $charges = array_map(
            static fn(DocumentChargeRow $row): FixedCharge => new FixedCharge(
                $row->label,
                Money::of($row->amount, $currency),
            ),
            $chargeRows,
        );

        return [
            new DocumentIdentity($document->id->toRfc4122(), $type, VatRoundingPoint::from($document->vatRoundingPoint)),
            Invoice::fromPersistedState(
                $currency,
                $state,
                null === $document->number
                    ? null
                    : new DocumentNumber($type, $this->numberPattern, $document->number),
                array_values($lines),
                array_values($charges),
            ),
        ];
    }
}
