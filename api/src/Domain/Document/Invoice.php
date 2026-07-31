<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Document;

use Twes\Domain\Document\Exception\DocumentCannotBeIssued;
use Twes\Domain\Document\Exception\DocumentIsNotMutable;
use Twes\Domain\Document\Exception\NumberTypeMismatch;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Money;
use Twes\Domain\Shared\RoundingMode;

/**
 * An invoice: the document lifecycle, its number and its lines, composed.
 *
 * **This class owns WHEN things may change, and nothing else.** It contains no arithmetic at all — every
 * figure comes from {@see DocumentCalculator}, which is driven by the shared cross-tier vectors in
 * `docs/spec/pricing-vectors.json`. That is the "one implementation, never two" invariant from
 * CLAUDE.md § Architecture: a second copy of a VAT formula is how two tiers of one product come to disagree
 * about tax owed to a state, and the disagreement shows up as a wrong number rather than a crash.
 *
 * **Immutable, and that is a correctness requirement rather than a style.** Every mutator returns a new
 * instance, so an issued document's snapshot cannot be moved by a later edit to anything — including to the
 * product a line was priced from. `pricing-and-documents.plan.md` requires prices to be snapshotted per line
 * precisely so that the same product at a different price later does not silently restate a document a client
 * already holds.
 *
 * **The lifecycle is delegated to {@see DocumentState}, never re-encoded here.** `issue()` and `cancel()` ask
 * `transitionTo()`, which throws on an illegal move — no status is ever written by assignment, which
 * CLAUDE.md names as a P0 for `domain-correctness-reviewer` because a status set by assignment is how an
 * illegal transition enters a billing system.
 *
 * **Deliberately absent: discounts, and inclusive-vs-exclusive tax.** Both are named in Wave 1's `In:` list and
 * neither is specified by any fixture. Building them would mean inventing money numbers, and this domain's whole
 * point is that it does not do that: they need worked examples from the developer first.
 *
 * Round 14 found this pointing at "the Wave 1 section, where they are recorded as owed with the reason" — and
 * that section contained only the `In:` list still claiming both in scope, unannotated; the deferral lived in a
 * Decisions Log entry ~515 lines above with no destination wave named. So a Wave 1 session reading its own
 * section was told both were in scope and sent here for a correction that was somewhere else. Both places are
 * now annotated in place.
 */
final readonly class Invoice
{
    /**
     * @param list<DocumentLine> $lines
     * @param list<FixedCharge> $fixedCharges
     */
    private function __construct(
        private Currency $currency,
        private DocumentState $state,
        private ?DocumentNumber $number,
        private array $lines,
        private array $fixedCharges,
    ) {}

    /**
     * A new, empty, mutable, unnumbered draft.
     *
     * **The currency is the DOCUMENT's and is fixed here**, rather than inferred from the first line. An
     * invoice being drafted in EUR is a EUR invoice before anything has been typed into it: the UI needs a
     * scale to format with, and `DocumentCalculator` needs one to total an empty document without guessing.
     * Inferring it from the first line would also make the currency depend on the order lines were added in.
     */
    public static function draft(Currency $currency): self
    {
        return new self($currency, DocumentState::Draft, null, [], []);
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function state(): DocumentState
    {
        return $this->state;
    }

    /** Null until issued; kept forever afterwards, including once cancelled. */
    public function number(): ?DocumentNumber
    {
        return $this->number;
    }

    /** @return list<DocumentLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<FixedCharge> */
    public function fixedCharges(): array
    {
        return $this->fixedCharges;
    }

    // ---------------------------------------------------------------- mutation, draft only

    /**
     * @throws DocumentIsNotMutable if this document is not a draft
     * @throws CurrencyMismatch if the line is not in this document's currency
     */
    public function withLine(DocumentLine $line): self
    {
        $this->assertMutable('withLine');

        // Refused HERE and not left to the calculator, so a draft can never hold a line it cannot total. The
        // calculator would catch it eventually; catching it at the edit means the error names the line the
        // user just added rather than surfacing later against a document that looks finished.
        if (!$this->currency->equals($line->unitNet()->currency())) {
            throw CurrencyMismatch::between($this->currency, $line->unitNet()->currency());
        }

        return new self(
            $this->currency,
            $this->state,
            $this->number,
            [...$this->lines, $line],
            $this->fixedCharges,
        );
    }

    /**
     * @throws DocumentIsNotMutable if this document is not a draft
     * @throws \OutOfBoundsException if there is no line at that position
     */
    public function withoutLine(int $position): self
    {
        $this->assertMutable('withoutLine');

        return new self(
            $this->currency,
            $this->state,
            $this->number,
            self::removeAt($this->lines, $position, 'line'),
            $this->fixedCharges,
        );
    }

    /**
     * @throws DocumentIsNotMutable if this document is not a draft
     * @throws CurrencyMismatch if the charge is not in this document's currency
     */
    public function withFixedCharge(FixedCharge $charge): self
    {
        $this->assertMutable('withFixedCharge');

        if (!$this->currency->equals($charge->amount()->currency())) {
            throw CurrencyMismatch::between($this->currency, $charge->amount()->currency());
        }

        return new self(
            $this->currency,
            $this->state,
            $this->number,
            $this->lines,
            [...$this->fixedCharges, $charge],
        );
    }

    /**
     * @throws DocumentIsNotMutable if this document is not a draft
     * @throws \OutOfBoundsException if there is no charge at that position
     */
    public function withoutFixedCharge(int $position): self
    {
        $this->assertMutable('withoutFixedCharge');

        return new self(
            $this->currency,
            $this->state,
            $this->number,
            $this->lines,
            self::removeAt($this->fixedCharges, $position, 'fixed charge'),
        );
    }

    // ---------------------------------------------------------------- transitions

    /**
     * Issue the invoice: assign its number and freeze it.
     *
     * The number comes from {@see DocumentNumberAllocator} and is NOT allocated here — the aggregate has no
     * collaborators. That also puts the allocation at issue time structurally: `draft()` takes no number, so an
     * abandoned draft cannot consume one, and a consumed number cannot fail to belong to a document.
     *
     * @throws Exception\IllegalTransition if this is not a draft
     * @throws NumberTypeMismatch if the number was allocated from another type's sequence
     * @throws DocumentCannotBeIssued if the invoice has no lines
     */
    public function issue(DocumentNumber $number): self
    {
        // The TRANSITION is asked first, so issuing an already-issued invoice reports a state error rather than
        // an emptiness or type error — the state is the more fundamental fact and the one a caller must fix.
        $state = $this->state->transitionTo(DocumentState::Issued);

        // Sequences are per TYPE and the digits alone are ambiguous — invoice 0000041 and delivery note
        // 0000041 both legitimately exist. `DocumentNumber` carries its type precisely so this is checkable
        // rather than a convention, and storing a foreign type's number would defeat the whole point of it.
        // A `\LogicException` (500), NOT the `\DomainException` (422) the emptiness check below raises, and the
        // split is deliberate: no API lets a client pick a document number, so reaching here means OUR
        // application layer allocated from the wrong sequence. Both conditions raised bare `\DomainException`
        // until these types existed, which left an HTTP layer matching message text to tell a fault from a
        // form error — the same defect round 13 closed for `Rate::fromPercentage()`.
        if (DocumentType::Invoice !== $number->type()) {
            throw NumberTypeMismatch::between(DocumentType::Invoice, $number);
        }

        // AN EMPTY INVOICE IS REFUSED, and this is a DERIVED decision rather than a ruled one — flagged so it
        // is not mistaken for a requirement. Nothing in the plans says whether an empty invoice may be issued.
        // It is refused because issuing consumes a number from a per-tenant sequence PERMANENTLY: numbers are
        // never reused, and a cancelled document stays on file forever precisely so its number is not
        // recycled. Issuing an empty invoice therefore burns a legal document number on a document with no
        // content, unrecoverably. If the developer rules otherwise this is one condition to delete.
        if ([] === $this->lines) {
            throw DocumentCannotBeIssued::becauseItHasNoLines();
        }

        return new self($this->currency, $state, $number, $this->lines, $this->fixedCharges);
    }

    /**
     * Cancel an issued invoice. It keeps its number and its figures, forever.
     *
     * Corrections go by **cancel-and-reissue** (developer ruling): the wrong document stays on file and a new
     * one is issued with the correct figures. Nothing is zeroed and nothing is edited — this instance is the
     * audit record of exactly what the client was sent, which is what makes the byte-identical-re-download
     * guarantee hold.
     *
     * @throws Exception\IllegalTransition if this is not an issued invoice
     */
    public function cancel(): self
    {
        return new self(
            $this->currency,
            $this->state->transitionTo(DocumentState::Cancelled),
            $this->number,
            $this->lines,
            $this->fixedCharges,
        );
    }

    // ---------------------------------------------------------------- figures

    /**
     * Every computed figure, from the one kernel.
     *
     * Computed on demand rather than stored, which is safe *because* this class is immutable: the same
     * instance and the same arguments always produce the same figures. Storing them would add a second place
     * for them to be wrong and would need invalidating on every mutation.
     *
     * Both parameters are the CALLER's, not defaults hidden here: the rounding point is per-company
     * configuration and the mode belongs to the operation. A default on either would be this class quietly
     * deciding a tax question.
     */
    public function totals(VatRoundingPoint $vatRoundingPoint, RoundingMode $mode): DocumentTotals
    {
        return new DocumentCalculator()->calculate(
            $this->lines,
            $this->fixedCharges,
            $vatRoundingPoint,
            $mode,
            $this->currency,
        );
    }

    // ---------------------------------------------------------------- internals

    /**
     * @throws DocumentIsNotMutable
     */
    private function assertMutable(string $operation): void
    {
        // Asks DocumentState rather than comparing to Draft, so "which states are mutable" has exactly one
        // definition. Comparing here would be a second copy of that rule, and the two would diverge the day a
        // state is added.
        if (!$this->state->isMutable()) {
            throw DocumentIsNotMutable::forOperation($operation, $this->state);
        }
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     *
     * @throws \OutOfBoundsException
     */
    private static function removeAt(array $items, int $position, string $what): array
    {
        // An out-of-range removal THROWS rather than being a silent no-op. A no-op here means a user clicked
        // "remove" on a stale page, the row stayed, and the document they then issued contains a line they
        // believe they deleted — which is a wrong legal document produced by a UI race.
        if (!\array_key_exists($position, $items)) {
            throw new \OutOfBoundsException(\sprintf(
                'There is no %s at position %d (the document has %d). Removing nothing silently would let a '
                . 'stale page issue a document containing a line the user believes they deleted.',
                $what,
                $position,
                \count($items),
            ));
        }

        unset($items[$position]);

        // Re-indexed, so positions stay contiguous and a later removal by position means what the caller sees.
        return array_values($items);
    }
}
