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
 * **THE NUMBER PATTERN WAS A CONSTRUCTOR DEPENDENCY, AND IT IS NOW GONE — CLOSED 2026-08-06.** `DocumentNumber` is
 * *(type, pattern, sequence)*. The row stores type and sequence; the PATTERN is per-tenant configuration, on a
 * settings table Wave 1 has not built. So reconstituting a number needed a pattern from somewhere, and it came from
 * here as a default width of 7.
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
 * **ALL FOUR OWED PIECES LANDED 2026-08-06** — this paragraph listed them as owed until then, and the accompanying
 * warning that "a document issued before the column lands and read after it may render differently, once" **never
 * materialised**: `select count(*) from document` was 0 in every database at the time the column landed, so no
 * document has ever been read back through the old default. `Version20260806…` adds the column with a
 * paired-nullability and a digits-only CHECK plus a backfill that is a no-op on empty data;
 * {@see DocumentRow::$numberRendered} is the field; {@see numberFrom()} is the read direction, which derives the
 * pattern from the stored string and then CHECKS the re-render against it; and `InvoiceMapperTest` carries six cases
 * including both refusals and the sequence-outran-its-padding case.
 *
 * The consequence worth carrying forward: **`toAggregate()` now consults no configuration at all**, so the settings
 * table this docblock waits on cannot affect an issued document's rendering when it arrives. It governs what NEW
 * numbers look like and nothing else, which is the only thing a tenant setting should ever have been able to do.
 */
final readonly class InvoiceMapper
{
    /*
     * NO CONSTRUCTOR, NO STATE, AND THAT IS THE POINT OF THE 2026-08-01 RULING.
     *
     * This class used to take a `NumberPattern` — nullable, defaulting to `padded(7)` — used for exactly one thing:
     * re-rendering a persisted sequence on the way out. Its own docblock called that "the uncomfortable part" and
     * certification round 21 filed it as a real hazard rather than a placeholder, because widening the pattern from
     * 7 to 8 re-rendered an already-issued `0000041` as `00000041`.
     *
     * With the rendering PERSISTED, the read path derives its pattern from the stored string ({@see numberFrom()})
     * and there is nothing left to inject. **That is strictly stronger than a correct default**: a default can be
     * wrong, and an absent dependency cannot. It also means the mapper is a pure function of its arguments, so
     * wiring it as a service later needs no configuration and no per-tenant scoping.
     */

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

        // THE CLIENT, as an assignment for the reason `DocumentRow::$clientId` gives: it is nullable on a draft
        // and required once issued, which is a relationship between two columns rather than a fact about one.
        $document->clientId = null === $invoice->clientId() ? null : Uuid::fromString($invoice->clientId());
        // BOTH HALVES OF THE NUMBER, and they stay assignments because they are the nullable pair a draft has
        // neither of. The RAW SEQUENCE identifies — `NumberPattern` renders and does not identify, so a padded
        // string alone would make the column unusable for ordering and for `(company_id, type, number)` uniqueness.
        // The RENDERED STRING is stored beside it so that no later configuration change can restate a document a
        // client already holds; see {@see DocumentRow::$numberRendered} for the ruling and the rejected alternatives.
        //
        // WRITTEN TOGETHER, ON PURPOSE. The database enforces the pairing (`document_number_halves_are_paired`) and
        // `toAggregate()` refuses a half, but the cheapest protection is that the two lines cannot be separated by
        // an edit that only looks at one of them — CLAUDE.md § Gotchas: decide a condition ONCE, where every path
        // reads it.
        $document->number = $invoice->number()?->sequence();
        $document->numberRendered = $invoice->number()?->number();

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
     *
     * @throws \Twes\Domain\Money\Exception\InvalidMoneyAmount if a stored amount cannot be represented in the
     *                                                         document's currency
     * @throws \LogicException if a child row belongs to another document, or the row is not an invoice
     *
     * **THE HYDRATION PATH, and it documented nothing.** The money columns are `NUMERIC(19,4)` while TND has
     * THREE decimals, so a value written with four significant decimals — by a migration, a direct `UPDATE`, or
     * another writer — comes back through `Money::of()` and is refused. Reproduced: `1.0001` read into TND.
     * That is the right behaviour (corrupt data must fail loudly) and it is a refusal a repository has to be
     * able to see in the signature.
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
                self::numberFrom($type, $document),
                // NO `array_values()`, unlike `DocumentCalculator`: `usort()` above re-indexes in place, so both
                // are already lists and `array_map()` preserves that. PHPStan reports the wrapper as having no
                // effect, and a call with no effect beside one that is load-bearing thirty lines away in another
                // file is how the load-bearing one gets deleted by analogy.
                $lines,
                $charges,
                $document->clientId?->toRfc4122(),
            ),
        ];
    }

    /**
     * Reconstitute a `DocumentNumber` FROM THE STORED STRING, with no configuration involved.
     *
     * **The pattern is derived from the string's own length, and that is exact rather than approximate.**
     * `NumberPattern::format()` is `str_pad($sequence, $width, '0', STR_PAD_LEFT)`, which grows past the padding
     * rather than truncating, so for any *(width, sequence)* the rendered string satisfies
     * `padded(strlen($rendered))->format($sequence) === $rendered`. [Verified: 48 of 48 combinations of width
     * {1,2,3,7,8,20} × sequence {1,9,10,41,99,100,12345678,PHP_INT_MAX}, zero mismatches.] So the string determines
     * a pattern that re-renders it byte-identically, and there is nothing left for a settings table to get wrong.
     *
     * What is NOT recovered is the AUTHORED width when the sequence outran it: width 3 with sequence 12345 renders
     * `12345`, and every width from 1 to 5 renders that document identically. Accepted, because the difference has
     * no observable effect on any document, payload or audit — see
     * `InvoiceMapperTest::testASequenceThatOutranItsPaddingRoundTripsItsRenderingButNotItsWidth()`.
     *
     * **AND THEN THE DERIVATION IS CHECKED**, which is what keeps it honest. CLAUDE.md § Gotchas 2026-07-31 records
     * a real P0 of exactly this shape: *"a control may not derive its own expected value from the input it is
     * validating"* — a policy checker read its column name out of the policy it was validating and therefore always
     * agreed with itself. Deriving a pattern from `$numberRendered` and then trusting it has the same structure, and
     * the same consequence: `0000041` stored against sequence 99 would read back as invoice 99 rendered `0000099`,
     * a number nobody ever issued. Comparing the re-render against the stored string is what turns a derivation into
     * a checked round trip, and it costs one `!==`.
     *
     * Both refusals are `\LogicException` — `error.internal` per CLAUDE.md § "Translation keys". A user cannot fix
     * either: one means a migration ran without its CHECK constraint, the other means the row is corrupt.
     *
     * @throws \LogicException if the two halves disagree, or if only one of them is present
     */
    private static function numberFrom(DocumentType $type, DocumentRow $document): ?DocumentNumber
    {
        if (null === $document->number) {
            // THE CONVERSE IS A REFUSAL TOO, not a silent null. A rendered string with no sequence is a document
            // that prints a number it cannot be ordered by or found under, and returning null here would hide it:
            // the aggregate would read back as a DRAFT while the row carries `0000041`, so `issue()` would allocate
            // a second number and the first would become a permanent hole in a gapless sequence.
            if (null !== $document->numberRendered) {
                throw new \LogicException(\sprintf(
                    'Document %s carries rendered number "%s" with no sequence. The sequence is what identifies a '
                    . 'document; a rendered string alone cannot be ordered by, made unique, or audited, and reading '
                    . 'this row back as a draft would let issue() allocate a second number and leave the first as a '
                    . 'permanent hole in a gapless sequence.',
                    $document->id->toRfc4122(),
                    $document->numberRendered,
                ));
            }

            return null;
        }

        if (null === $document->numberRendered) {
            throw new \LogicException(\sprintf(
                'Document %s carries sequence %d with no rendered number. The rendered string is what makes a '
                . 're-download byte-identical, and re-rendering from a default width here is precisely the '
                . 'behaviour the 2026-08-01 ruling removed — it is how an already-issued 0000041 becomes 00000041. '
                . 'The migration forbids this pairing, so reaching it means the constraint is missing or our own '
                . 'layer built the row wrong.',
                $document->id->toRfc4122(),
                $document->number,
            ));
        }

        // `NumberPattern::padded()` refuses a width outside 1..MAX_WIDTH, so a corrupt string of 21 characters
        // throws here rather than rendering a legal-looking number. Deliberately NOT clamped with `min()`: a clamp
        // would silently accept the corruption and then fail the comparison below with a confusing message, and the
        // clamp itself would be dead code for every valid value — `PHP_INT_MAX` is 19 digits and MAX_WIDTH is 20.
        $number = new DocumentNumber($type, NumberPattern::padded(\strlen($document->numberRendered)), $document->number);

        if ($number->number() !== $document->numberRendered) {
            throw new \LogicException(\sprintf(
                'Document %s has a rendered number stored as "%s", but sequence %d renders as "%s". The two halves '
                . 'of a document number disagree, so this row cannot be read back without inventing a number: '
                . 'returning either one would put a figure on a legal document that nobody issued.',
                $document->id->toRfc4122(),
                $document->numberRendered,
                $document->number,
                $number->number(),
            ));
        }

        return $number;
    }
}
