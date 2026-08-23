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
use Twes\Domain\Document\Exception\DocumentCannotBeTotalled;
use Twes\Domain\Document\Exception\DocumentIsNotMutable;
use Twes\Domain\Document\Exception\NumberTypeMismatch;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Exception\InvalidMoneyAmount;
use Twes\Domain\Money\Money;
use Twes\Domain\Shared\Identifier;
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
 * **Deliberately absent: discounts, and inclusive-vs-exclusive tax.** Both are DEFERRED TO WAVE 2, and neither
 * is specified by any fixture.
 *
 * The claim that "both are named in Wave 1's `In:` list" was false for discounts by the time it was written
 * (round 15): the same commit that annotated the deferral had already deleted the word from that list, so the
 * deferral was expressed by DELETION for one item and by ANNOTATION for the other, and this cross-reference was
 * not re-read against either. Building them would mean inventing money numbers, and this domain's whole
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
     * The most lines or charges a document may hold — a **derived** bound, and the constant
     * `build-waves.plan.md` records `document_line.position smallint` as owing.
     *
     * Round 16 filed the gap: `position` was specified as a `smallint` with no constant behind it, which is the
     * same domain-admits-what-persistence-rejects mismatch the `document.number integer -> bigint` and
     * `NumberPattern::MAX_WIDTH` fixes closed. Nothing bounded the number of lines at all, so a document could
     * hold more than the column could address.
     *
     * A thousand, because a document a human reads is not a data feed: at a thousand lines an invoice is already
     * unprintable, and anything wanting more is a statement or an export rather than a document. It fits
     * `smallint`, whose maximum is 32 767 — so this bound leaves a factor of about **32**, not the "two orders
     * of magnitude" this line claimed for a round (`log10(32767 / 1000)` is 1.5). Ample either way, and the
     * correction is kept rather than quietly deleted because the number is what a future reader would rely on
     * when deciding whether raising `MAX_LINES` also needs a column change: it does, above 32 767.
     * Like `DocumentLine::MAX_SCALE` this is derived rather than ruled — one constant and one column type if the
     * developer needs more.
     */
    public const int MAX_LINES = 1000;

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
        private ?string $clientId,
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
        return new self($currency, DocumentState::Draft, null, [], [], null);
    }

    /**
     * REHYDRATE a document that was already validated when it was written. The persistence seam, and nothing else.
     *
     * @internal for repositories only. Every other caller starts at {@see self::draft()} and moves through the
     *           transition guard, which is what makes an illegal state unreachable at runtime.
     *
     * **Why a factory rather than replaying the transitions** (developer ruling, 2026-08-01, after both options were
     * put side by side). Replaying — `draft()`, then `withLine()` per line, then `issue()`/`cancel()` — needs no new
     * API and re-checks every invariant on load, which is genuinely attractive. It was rejected because it makes
     * hydration depend on TODAY's guards permitting every historical document's path: tighten a rule in Wave 2 and
     * already-issued invoices stop loading, which is a data migration wearing the costume of a validation
     * improvement, and unfixable once real invoices exist. A document that was legal when issued must stay
     * loadable forever — a tax authority can ask for it years later.
     *
     * So this bypasses the guard ON PURPOSE, and that is safe for exactly one reason: the state it takes was
     * already guarded when it was written. It is NOT safe as a general constructor, which is why it is `@internal`
     * and why `scripts/gates/` has no business seeing it called from `UI/` or `Application/`.
     *
     * The lines and charges are NOT bypassed. `DocumentLine` and `FixedCharge` have public constructors and
     * re-validate on the way in, which is deliberate rather than an inconsistency: their PER-LINE bounds are pinned
     * to the column types (`NUMERIC(21,6)` matches `MAX_INTEGER_DIGITS` 15 + `MAX_SCALE` 6), so the column cannot
     * hold a value the constructor would refuse, and the guard-tightening hazard above does not arise for THOSE.
     *
     * **WHAT IS DELIBERATELY NOT RE-CHECKED, stated because the previous version of this paragraph said the hazard
     * "simply does not arise for them" full stop — which was false and is the kind of sentence this project has
     * recorded five times as the expensive sort of error, since a documented impossibility gets read once and never
     * re-tested.** `withLine()` enforces three AGGREGATE invariants that no column type can pin, and this factory
     * re-checks none of them: the line's currency matching the document's, `assertRoomFor()` against
     * {@see self::MAX_LINES}, and `totallable()`. Round 22 got 1005 lines, a foreign-currency line and an
     * untotallable document through here.
     *
     * That is accepted for two of the three and guarded for the third, and the split is on REACHABILITY rather than
     * severity. Currency mismatch is unreachable through `InvoiceMapper`, which builds every `Money` from the
     * document's own currency column — so re-checking it would cost a loop over every line on every read to catch
     * nothing. The line cap and totallability are reachable only by a direct write to the database, which is outside
     * this domain's threat model — and NOT inside `schema-tenancy.php`'s either, which this sentence claimed until
     * 2026-08-02: that gate asserts tenancy properties (row security, policies, keys, ownership), not aggregate
     * bounds. A direct write past the line cap is guarded by nothing today, which is the honest statement. **An issued document with NO LINES is guarded
     * below**, because that one is reachable through the ordinary repository path — a half-committed rewrite — and
     * it renders as an invoice whose total is 0.000.
     *
     * @param list<DocumentLine> $lines in persisted position order
     * @param list<FixedCharge> $fixedCharges in persisted position order
     */
    public static function fromPersistedState(
        Currency $currency,
        DocumentState $state,
        ?DocumentNumber $number,
        array $lines,
        array $fixedCharges,
        ?string $clientId,
    ): self {
        // The one invariant still worth asserting here, because it is a PERSISTENCE bug rather than a domain one and
        // it is silent: a document in a numbered state with no number, or a draft carrying one, means the mapper
        // dropped or invented a column. Cheap to check, and it fails at the boundary instead of surfacing later as
        // an invoice with no number on a PDF.
        if (DocumentState::Draft === $state && null !== $number) {
            throw new \LogicException(
                'A draft cannot carry a document number: it is allocated at issue. A number on a draft row means '
                . 'the write path assigned one early, which would burn a number from a gapless sequence.',
            );
        }

        if (DocumentState::Draft !== $state && null === $number) {
            throw new \LogicException(\sprintf(
                'An %s document must carry its number — it is allocated at issue and kept forever afterwards, '
                . 'including once cancelled. A null here means the column was lost, which destroys the audit '
                . 'trail a tax authority reads.',
                $state->value,
            ));
        }

        // AND AN ISSUED DOCUMENT MUST SAY WHO IT IS ADDRESSED TO. The same reasoning as the number guards above:
        // `issue()` refuses to create one without a client, so a row that comes back without one means the
        // mapper dropped the column or the row predates the rule. Failing at the boundary is what stops it
        // surfacing later as an invoice addressed to nobody on a PDF a tax authority reads.
        if (DocumentState::Draft !== $state && null === $clientId) {
            throw new \LogicException(\sprintf(
                'An %s invoice must carry its client — EN 16931 makes the buyer mandatory (BT-44), and `issue()` '
                . 'refuses a draft without one, so a null here means the column was lost rather than never set.',
                $state->value,
            ));
        }

        // AN ISSUED DOCUMENT MUST HAVE CONTENT. `issue()` refuses to create one from an empty draft
        // (`DocumentCannotBeIssued`), and the same document must not be able to COME BACK empty. The rationale is the
        // one the number guard above gives: it fails at the boundary rather than surfacing later on a PDF -- and this
        // is the worse surface, because the document renders with a total of 0.000 and looks finished.
        //
        // Reachable through the ordinary repository path rather than only by hand: a document is rewritten whole on
        // save, so a delete-then-insert of line rows that half-commits, or a line query bound to a different tenant
        // than its parent (`set_config(..., true)` is transaction-local and `document_line` carries its own policy),
        // leaves the parent row present and the children gone.
        if (DocumentState::Draft !== $state && [] === $lines) {
            throw new \LogicException(\sprintf(
                'An %s document has no lines. `issue()` refuses to create one from an empty draft, so a persisted '
                . 'document that comes back empty means its line rows were lost — a half-committed rewrite, or a '
                . 'query whose tenant binding differed from its parent\'s. It would render as an invoice whose '
                . 'total is zero, which looks finished rather than broken.',
                $state->value,
            ));
        }

        return new self($currency, $state, $number, $lines, $fixedCharges, $clientId);
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * The client this document is addressed to, or `null` while it is still a draft.
     *
     * **AN ID RATHER THAN A `Client`, and that is DDD rather than laziness.** One aggregate references another
     * by IDENTITY, never by object: holding a `Client` would make loading an invoice load a client, make the
     * two a single consistency boundary, and let a stale copy of a client travel inside a document. The F4
     * snapshot rule is the same instinct applied to money — a line carries a `Money` by value and no product id
     * — and the difference is deliberate: a PRICE must never change under an issued document, while the client
     * a document names is a living record whose address may legitimately be corrected.
     */
    public function clientId(): ?string
    {
        return $this->clientId;
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
     * @throws \InvalidArgumentException if the document is already full (`MAX_LINES`), or if adding this line
     *                                   would make the document impossible to total
     *
     * **THE THIRD TAG WAS ABSENT, on a PUBLIC MUTATOR AN HTTP LAYER CALLS.** `assertRoomFor()` and
     * `totallable()` both raise it, and `CLAUDE.md` § "Translation keys" keys both as user-fixable —
     * `document.line_count_too_large` and `document.total_too_large`. A refusal a user can act on is exactly
     * the one whose signature has to say so, because that section's test is whether a competent user reading
     * only the message would know what to change.
     */
    public function withLine(DocumentLine $line): self
    {
        $this->assertMutable('withLine');

        // Refused HERE and not left to the calculator, so a draft can never hold a line it cannot total.
        //
        // **THE REASON THIS COMMENT USED TO GIVE WAS FALSE, AND BACKWARDS** (round 4, R4C-8). It read *"the
        // calculator would catch it eventually; catching it at the edit means the error names the line the user just
        // added rather than surfacing later against a document that looks finished"*. There is no "eventually":
        // `self::totallable()` runs at the bottom of this very method, so the calculator catches it in THIS call —
        // and its message is the better of the two, because it is the one that names the line. [Verified 2026-08-23
        // with the guard neutered: `Cannot combine TND with EUR (document line 0). …` against this guard's
        // `Cannot combine TND with EUR. …`.] Six mismatch shapes — first line, later line, zero-amount line, a
        // charge on an empty document, a zero-amount charge, a charge after a good line — are all still refused
        // without it.
        //
        // So it is DEFENCE IN DEPTH and is kept as that rather than deleted, for one reason worth stating:
        // `totallable()` landed on 2026-08-23 for an unrelated finding (R4C-2, the document-total overflow), and
        // before it these guards were the whole rule. Deleting them would make an invariant that predates it depend
        // on it, with nothing anywhere asserting that every mutator must end in a total. A guard is not redundant
        // because one recent call path happens to cover it.
        //
        // Pinned by `testTheEditTimeCurrencyGuardRefusesBeforeTheCalculatorDoes`, which asserts the message WITHOUT
        // a `(document …)` locator — the only thing that distinguishes this refusal from the calculator's.
        if (!$this->currency->equals($line->unitNet()->currency())) {
            throw CurrencyMismatch::between($this->currency, $line->unitNet()->currency());
        }

        self::assertRoomFor($this->lines, 'line');

        return self::totallable(new self(
            $this->currency,
            $this->state,
            $this->number,
            [...$this->lines, $line],
            $this->fixedCharges,
            $this->clientId,
        ), 'line');
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
            $this->clientId,
        );
    }

    /**
     * @throws DocumentIsNotMutable if this document is not a draft
     * @throws CurrencyMismatch if the charge is not in this document's currency
     * @throws \InvalidArgumentException if the document is already full (`MAX_LINES`), or if adding this charge
     *                                   would make the document impossible to total — see `withLine()`;
     *                                   `document.charge_count_too_large` and `document.total_too_large`
     */
    public function withFixedCharge(FixedCharge $charge): self
    {
        $this->assertMutable('withFixedCharge');

        // Defence in depth, exactly as on `withLine()` above — see that guard for the measurement, for why the
        // calculator's message is the better one, and for why these are kept rather than deleted. Pinned
        // SEPARATELY, because two guards are two mutants and one can be dropped while the other still refuses:
        // the first version of that test guessed a per-arm discriminator and this one survived it.
        if (!$this->currency->equals($charge->amount()->currency())) {
            throw CurrencyMismatch::between($this->currency, $charge->amount()->currency());
        }

        self::assertRoomFor($this->fixedCharges, 'fixed charge');

        return self::totallable(new self(
            $this->currency,
            $this->state,
            $this->number,
            $this->lines,
            [...$this->fixedCharges, $charge],
            $this->clientId,
        ), 'fixed charge');
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
            $this->clientId,
        );
    }

    /**
     * Address this document to a client, or clear it.
     *
     * **A MUTATION LIKE ANY OTHER, so it is refused once the document is no longer mutable.** An issued invoice
     * naming client A cannot be re-pointed at client B: that is not a correction, it is a different legal
     * document, and this project's answer to a wrong issued document is cancel-and-reissue rather than an edit.
     * `assertMutable()` enforces that, exactly as it does for lines and charges.
     *
     * **NULL IS ACCEPTED, and clearing is a real operation rather than an oversight.** A user who picked the
     * wrong client on a draft must be able to unpick it without deleting the draft. {@see self::issue()} is
     * where the requirement bites.
     *
     * @throws \InvalidArgumentException if the id is not a canonical UUID
     * @throws Exception\DocumentIsNotMutable if this document is no longer a draft
     */
    public function withClient(?string $clientId): self
    {
        $this->assertMutable('withClient');

        // VALIDATED BY THE TYPE THAT OWNS THE RULE, not by a second regex. `Identifier` is the one canonical
        // spelling of "is this a well-formed id" in this codebase, extracted precisely so a third copy of that
        // pattern could not drift from the other two.
        if (null !== $clientId && !Identifier::isWellFormed($clientId)) {
            throw new \InvalidArgumentException(\sprintf(
                'A client id must be a canonical lowercase-hyphenated UUID, got "%s". Refused here rather than '
                . 'passed to the database, where the foreign key would raise a type error instead of naming the '
                . 'field a caller can fix.',
                $clientId,
            ));
        }

        return new self(
            $this->currency,
            $this->state,
            $this->number,
            $this->lines,
            $this->fixedCharges,
            $clientId,
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

        // AND AN INVOICE MUST SAY WHO IT IS ADDRESSED TO. Ruled 2026-08-22 and DERIVED in the same sense the
        // emptiness check above is derived — the plans were silent, so the argument is recorded in
        // `build-waves.plan.md`'s Decisions Log rather than inherited from a column's nullability.
        //
        // EN 16931 makes the buyer mandatory (BT-44). A DRAFT may have none, for the same reason it may have no
        // lines: deciding who an invoice is for can legitimately come after typing what is on it. The
        // requirement therefore attaches at the TRANSITION, which is what makes this guard the line guard's
        // sibling rather than a special case — and why neither belongs on the constructor.
        if (null === $this->clientId) {
            throw DocumentCannotBeIssued::becauseItHasNoClient();
        }

        return new self($this->currency, $state, $number, $this->lines, $this->fixedCharges, $this->clientId);
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
            $this->clientId,
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
     *
     * **THIS METHOD HAD NO `@throws` AT ALL, and it is the one an HTTP layer will call.** It carried none while
     * `totallable()` fifty lines below catches `InvalidMoneyAmount` out of it by name — so the class documented
     * the refusal in its own catch block and denied it in the signature a caller reads.
     * `CLAUDE.md` § "Translation keys" decides which refusals get a locale key by asking which ones a user can
     * fix, and that question cannot be answered from a signature that lists nothing.
     *
     * **CATCH `InvalidMoneyAmount` FIRST: it IS an `\InvalidArgumentException`.** The chain is
     * `InvalidMoneyAmount` → `\InvalidArgumentException` → `\LogicException` [Verified: `class_parents()`], so a
     * transport layer catching in the order a `@throws` list happens to be written mis-keys the money overflow.
     *
     * That is not hypothetical — this docblock had a third tag reading *"`@throws \InvalidArgumentException` if the
     * document is empty and no currency can be inferred"*, and it was wrong twice over. It **cannot fire on that
     * reason**: `totals()` always passes `$this->currency`, a non-nullable property, and `resolveCurrency()` raises
     * that exception only from `?? throw` on a null currency [Verified: `Invoice::draft(EUR)->totals(...)` returns a
     * total of `0.00`]. And being the PARENT of the tag above it, it was reachable only as a mis-catch — so a
     * reader building the HTTP layer from this list would have reported `money.amount_not_representable` as *"the
     * document is empty"*, which is precisely the harm the § "Translation keys" reference below exists to prevent.
     * Removed rather than reworded, and the hierarchy stated instead.
     *
     * @throws CurrencyMismatch if a line or charge is in another currency — our own layer's fault, so
     *                          `error.internal` rather than a key
     * @throws InvalidMoneyAmount if any figure does not fit the money column (`money.amount_not_representable`)
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
     * **A DRAFT MAY NEVER HOLD A LINE SET IT CANNOT TOTAL, and round 16 found that it could.**
     *
     * `DocumentLine` bounds one line's `quantity × unitNet`; nothing bounded the SUM. So two in-bounds lines, or
     * even a single line whose net fits while `net + VAT` does not, produced a draft that could be ISSUED — number
     * consumed permanently from a gapless legal sequence, state frozen — after which `totals()` raised forever,
     * `cancel()` included, so the audit record could never be rendered. `Money`'s own docblock already stated the
     * shape the line guard missed: *"two representable amounts can sum to an unrepresentable one"*.
     *
     * That is the THIRD iteration of one defect — `ProductPricing` at rounds 5–6, the line product at round 15,
     * the sum here — and each fix bounded one level and asserted the next was therefore safe. The invariant is not
     * about factors or products; it is that **every figure a document will ever be asked for must be computable
     * before the document can hold the input**. So this checks the whole document rather than the increment.
     *
     * `withLine()`'s own comment already required this: *"Refused HERE and not left to the calculator, so a draft
     * can never hold a line it cannot total."* That sentence was true of currency and false of magnitude.
     *
     * `RoundingMode::Up` is away-from-zero and therefore the largest magnitude any mode can produce, so passing
     * this proves the document totals under EVERY mode a caller may later choose. `PerRateGroup` is used because
     * `PerLine` cannot exceed it: rounding each line up and summing is bounded by rounding the summed base up.
     *
     * Cost is O(n) per edit, which is accepted for the same reason the currency check is: a wrong legal document
     * is worse than a slow one, and the alternative is an unrenderable document with a spent number.
     *
     * **MEASURED, because "O(n) per edit" makes the AGGREGATE quadratic and the previous version of this note
     * left that to be inferred** (round 17's F6). Building a document one `withLine()` at a time, on this
     * container, PHP 8.5.9:
     *
     *     n =  100   all edits    176.8 ms    one totals()   3.30 ms
     *     n =  250    "         1 094.4 ms      "            8.73 ms
     *     n =  500    "         4 681.7 ms      "           18.57 ms
     *     n = 1000    "        18 399.7 ms      "           36.14 ms
     *
     * The interactive case is comfortable: the LAST edit of a 1000-line document costs ~36 ms, so
     * one-edit-per-request HTTP editing is never the problem, and that is the only caller today.
     *
     * **A BULK PATH MUST NOT USE THIS METHOD PER LINE.** An importer building a 1000-line invoice in one request
     * would pay ~18 s of CPU. When such a caller exists it needs a `withLines(iterable $lines)` that appends all
     * of them and totals ONCE — same guarantee, paid once, O(n) overall rather than O(n²). Deliberately not built
     * yet: there is no importer, and inventing API for an absent caller is how a signature ends up wrong. The
     * numbers are recorded here so that decision is made against measurements instead of re-measured.
     *
     * @throws \InvalidArgumentException if the document could not be totalled
     */
    private static function totallable(self $document, string $what): self
    {
        try {
            $document->totals(VatRoundingPoint::PerRateGroup, RoundingMode::Up);
        } catch (InvalidMoneyAmount $overflow) {
            // TYPED, and the message is unchanged. A bare `\InvalidArgumentException` here is indistinguishable at
            // the transport from the one `InvoiceMapper` raises for a corrupt column — so `CreateInvoiceProcessor`
            // could not catch this without re-admitting that, and the caller got a 500 (round 4, R4C-2).
            // `DocumentCannotBeTotalled` EXTENDS `\InvalidArgumentException`, so every `@throws` tag on the three
            // mutators that reach here stays true and no existing `catch` changes behaviour.
            throw DocumentCannotBeTotalled::afterAdding($what, $overflow);
        }

        return $document;
    }

    /**
     * @param list<mixed> $existing
     *
     * @throws \InvalidArgumentException if the document is already at MAX_LINES
     */
    private static function assertRoomFor(array $existing, string $what): void
    {
        if (\count($existing) < self::MAX_LINES) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'This document already holds %d %ss, which is the maximum. A document a human reads is not a data '
            . 'feed: past this it is a statement or an export rather than an invoice, and the persisted position '
            . 'column is sized from this bound.',
            self::MAX_LINES,
            $what,
        ));
    }

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
