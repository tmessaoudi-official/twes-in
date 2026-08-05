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
 * That was a REAL HAZARD rather than a placeholder — filed by certification round 21, because an administrator
 * widening the width from 7 to 8 re-renders an already-issued invoice as `00000041` instead of `0000041`: a different
 * number on a legal document a client already holds.
 *
 * **RULED 2026-08-01: the RENDERED STRING IS PERSISTED alongside the sequence, and this class is where that lands.**
 * One column. The sequence stays the identity — it is what `(company_id, type, number)` uniqueness and every ORDER BY
 * are built on — and the string is what makes re-download byte-identical forever, so no later configuration change
 * can restate a document somebody holds. Rejected: snapshotting the pattern per document (equivalent guarantee, more
 * indirection) and ruling rendering presentational (the cheaper reading, and unfixable once real invoices exist —
 * the same class of decision as the gapless sequence and money-is-never-a-float).
 *
 * **STILL OWED, and this constructor dependency is the placeholder until it lands:** a migration adding the column,
 * the field on `DocumentRow`, both directions here, and a round-trip case. Until then `toAggregate()` re-renders
 * from a default width, which is exactly the behaviour the ruling exists to remove — so a document issued before the
 * column lands and read after it may render differently, once. `build-waves.plan.md`'s Decisions Log carries the
 * ruling; this docblock said "a product decision owed to the developer" until the ruling was made, and leaving that
 * sentence in place afterwards is the stale-prose shape `CLAUDE.md` § Gotchas records repeatedly.
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
        // THIS MAPPER IS FOR INVOICES, and neither direction said so. `document` is ONE table for all four types
        // (`CHECK (type IN ('invoice','quote','credit','delivery_note'))`), and `Invoice::issue()` hard-codes
        // `DocumentType::Invoice` when it allocates a number. Writing an `Invoice` under `type='quote'` files invoice
        // number 41 as a quote -- and since uniqueness is scoped `(company_id, type, number)`, the gapless INVOICE
        // sequence acquires a permanent hole, which CLAUDE.md § Gotchas calls what a tax authority reads as a
        // suppressed sale. A `\LogicException`: our own layer built the request wrong, so it is `error.internal`.
        if (DocumentType::Invoice !== $identity->type) {
            throw new \LogicException(\sprintf(
                'InvoiceMapper was handed a %s identity. One table holds all four document types, and an Invoice '
                . 'aggregate written under another type files its number in that type\'s sequence — leaving a '
                . 'permanent hole in the invoice sequence, which is what an audit reads as a suppressed sale.',
                $identity->type->value,
            ));
        }

        // EVERY NOT-NULL COLUMN THROUGH THE CONSTRUCTOR, never by assignment. See
        // {@see DocumentRow::__construct()}: a forgotten assignment used to surface as
        // `must not be accessed before initialization` from inside `flush()`, far from the line that forgot it.
        $document = new DocumentRow(
            Uuid::fromString($tenant->toString()),
            Uuid::fromString($identity->id),
            $identity->type->value,
            $invoice->state()->value,
            $invoice->currency()->code(),
            $identity->vatRoundingPoint->value,
        );
        // `number` STAYS an assignment, because it is the one nullable column and a draft has none. The RAW
        // SEQUENCE, never the rendered string: `NumberPattern` renders, it does not identify — so persisting
        // `0000041` would bake today's pattern into the row and make the column unusable for ordering or
        // uniqueness.
        $document->number = $invoice->number()?->sequence();

        $lines = [];

        foreach ($invoice->lines() as $position => $line) {
            $lines[] = new DocumentLineRow(
                Uuid::fromString($tenant->toString()),
                Uuid::fromString($identity->id),
                // The ARRAY INDEX is the position, and `Invoice` guarantees it is contiguous: `withoutLine()`
                // re-indexes through `removeAt()` precisely so positions stay dense. Persisting the index rather
                // than a counter is what makes "remove line 2" mean the same thing before and after a round trip.
                $position,
                $line->quantity(),
                $line->unitNet()->amount(),
                // The FRACTION, not the percentage: `Rate::fraction()` is the exact stored form, and
                // `NUMERIC(27,12)` is sized from `FRACTION_SCALE` (12) + `MAX_INTEGER_DIGITS` (15). Persisting the
                // percentage would divide by 100 on the way back in and lose the twelfth decimal on 5.5%.
                $line->vatRate()->fraction(),
            );
        }

        $charges = [];

        foreach ($invoice->fixedCharges() as $position => $charge) {
            $charges[] = new DocumentChargeRow(
                Uuid::fromString($tenant->toString()),
                Uuid::fromString($identity->id),
                $position,
                $charge->label(),
                $charge->amount()->amount(),
            );
        }

        return [$document, $lines, $charges];
    }

    /**
     * @param array{DocumentRow, list<DocumentLineRow>, list<DocumentChargeRow>} $rows
     *
     * @return array{DocumentIdentity, Invoice}
     */
    public function toAggregate(TenantId $tenant, array $rows): array
    {
        [$document, $lineRows, $chargeRows] = $rows;

        /*
         * THE TENANT IS AN ARGUMENT, AND EVERY ROW IS CHECKED AGAINST IT.
         *
         * This method took no tenant and validated nothing, which made it a TENANT-LESS HYDRATION PATH -- precisely
         * what `DocumentIdentity`'s docblock claims the repository's explicit tenant prevents. Round 22 handed it
         * rows from two tenants and got one `Invoice` carrying tenant B's line on tenant A's document. That is not a
         * leak in the reading sense; it is a WRONG LEGAL DOCUMENT, which is worse, and no policy can catch it
         * because the rows were already fetched.
         *
         * The parent id is checked for the same reason: `document_line` carries its own policy and its own
         * `document_id`, so a query that forgets `AND document_id = ?` returns every line of the tenant.
         *
         * Checked HERE rather than left to the repository deliberately. The repository is the thing that will be
         * written many times -- one per aggregate, by whoever needs one -- and this is the single place all of them
         * funnel through. A check every caller must remember is the weakest of the three options this project
         * records; this is the one that cannot be forgotten.
         */
        $expectedTenant = $tenant->toString();

        if ($document->companyId->toRfc4122() !== $expectedTenant) {
            throw new \LogicException(\sprintf(
                'Document row belongs to tenant %s but hydration was bound to %s. A tenant-less or mis-bound '
                . 'hydration path is what the boundary rule exists to prevent — refused here rather than returning '
                . 'another tenant\'s document.',
                $document->companyId->toRfc4122(),
                $expectedTenant,
            ));
        }

        foreach ([...$lineRows, ...$chargeRows] as $child) {
            if ($child->companyId->toRfc4122() !== $expectedTenant) {
                throw new \LogicException(\sprintf(
                    'A child row at position %d belongs to tenant %s, not %s. Merging it would put another '
                    . "tenant's figures on this document — a wrong legal document, not merely a leak.",
                    $child->position,
                    $child->companyId->toRfc4122(),
                    $expectedTenant,
                ));
            }

            if ($child->documentId->toRfc4122() !== $document->id->toRfc4122()) {
                throw new \LogicException(\sprintf(
                    'A child row at position %d belongs to document %s, not %s. A query missing its '
                    . 'document_id predicate returns every line the tenant owns.',
                    $child->position,
                    $child->documentId->toRfc4122(),
                    $document->id->toRfc4122(),
                ));
            }
        }

        $currency = Currency::of($document->currency);
        $type = DocumentType::from($document->type);
        $state = DocumentState::from($document->state);

        // The READ side of the type guard in `toRows()`. A repository whose query omits `AND type = 'invoice'` hands
        // a quote row here; without this, it comes back as an `Invoice` aggregate paired with a `DocumentIdentity`
        // saying `Quote` — an internally inconsistent pair from one call, and a quote rendered as an invoice.
        if (DocumentType::Invoice !== $type) {
            throw new \LogicException(\sprintf(
                'Row %s is a %s, not an invoice. InvoiceMapper would return an Invoice aggregate paired with a '
                . 'non-invoice identity — add `AND type = \'invoice\'` to the query that fetched it.',
                $document->id->toRfc4122(),
                $type->value,
            ));
        }

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
                // NO `array_values()`, unlike `DocumentCalculator`: `usort()` above re-indexes in place, so both
                // are already lists and `array_map()` preserves that. PHPStan reports the wrapper as having no
                // effect, and a call with no effect beside one that is load-bearing thirty lines away in another
                // file is how the load-bearing one gets deleted by analogy.
                $lines,
                $charges,
            ),
        ];
    }
}
