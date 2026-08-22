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

use ApiPlatform\Metadata\Get;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twes\Domain\Document\DocumentIdentity;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\DocumentNumber;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\InvoiceRepository;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\PersistedInvoice;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Tests\Support\RecordingTransactionalScope;
use Twes\UI\Http\ApiResource\InvoiceResource;
use Twes\UI\Http\State\InvoiceProvider;
use Twes\UI\Http\State\InvoiceRepresentation;

/**
 * THE INVOICE READ REPRESENTATION — what goes on the wire, and what must not.
 *
 * Driven through a fake repository rather than a booted kernel: the subject is the TRANSLATION, and a kernel round
 * trip would prove routing and serialisation instead. `DoctrineInvoiceRepositoryTest` covers the repository against
 * a real schema; this covers what the provider does with what it returns.
 *
 * The fake implements the domain PORT, which is the point of having one — no mock framework, no partial double, and
 * the test cannot drift from the interface because PHP would refuse to compile it.
 */
#[CoversClass(InvoiceProvider::class)]
// AND THE REPRESENTATION, which is where most of what this class asserts now lives: the translation was extracted from
// the provider when the write path landed, because `GET`, `POST` and the issue transition all answer with the same
// resource and the figures they assemble must not differ between a create response and a later fetch.
#[CoversClass(InvoiceRepresentation::class)]
final class InvoiceProviderTest extends TestCase
{
    /**
     * Every invoice fixture is addressed to a client, because since 2026-08-22 `issue()`
     * requires one — EN 16931 makes the buyer mandatory (BT-44) and an issued invoice
     * addressed to nobody is not a document a tax authority accepts. A DRAFT may have none;
     * these fixtures carry one because a realistic invoice does.
     */
    private const FIXTURE_CLIENT = '0199a5b2-0000-7000-8000-00000000c101';

    private const DOCUMENT = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

    private RecordingTransactionalScope $scope;

    protected function setUp(): void
    {
        $this->scope = new RecordingTransactionalScope();
    }

    /**
     * THE LOOKUP RUNS INSIDE A TRANSACTION, and this assertion is the only thing in this class that makes it so.
     *
     * It reads like ceremony on a read and it is not. The tenant is bound to the database session by
     * `TenantBindingConnection` at `beginTransaction()`, using `set_config(..., true)` — TRANSACTION-LOCAL, because a
     * session-scoped value survives into whoever gets the pooled connection next. So a query issued with no
     * transaction open is issued UNBOUND, the canonical policy compares `company_id` against NULL, and a tenant
     * asking for its own document gets a 404. That was the state of this endpoint until the MAXIMAL panel found it.
     *
     * WHY the unbound read sees nothing is a property of the database and is proven against a real one in
     * `TenantBindingMiddlewareTest::testTheSameFetchOutsideATransactionSeesNothing()`. What is asserted HERE is the
     * only half a double can see — that the provider asks for a scope at all — and it is what goes red if somebody
     * removes the wrapper as redundant. Neither case is sufficient alone: this one would pass against a scope that
     * committed nothing, and that one would pass against a provider nobody had wired.
     *
     * `maxDepth` as well as `entered`, because one flat scope is the requirement: a provider that nested would look
     * transactional here while, through DBAL, opening a SAVEPOINT whose rollback reverts the binding — the divergence
     * `SavepointTenantBindingConnection` exists to catch.
     */
    public function testTheLookupRunsInsideExactlyOneTransaction(): void
    {
        $this->represent($this->tndInvoice());

        self::assertSame(1, $this->scope->entered, 'the read opens a transaction, because the binding is local to one');
        self::assertSame(1, $this->scope->maxDepth, 'one flat scope, not a nested one');
    }

    /**
     * EVERY MONETARY AND QUANTITY FIELD IS A JSON **STRING**, NOT A JSON NUMBER. The contract decision this whole
     * resource rests on, asserted where it is actually observable.
     *
     * **The first version of this test called `assertIsString()` on each field and PHPStan refused it** — the DTO
     * properties are declared `string`, so every one of those assertions was provably true and therefore dead. That
     * is the half-hollow-assertion class `CLAUDE.md` records its PHPStan configuration catching before, and the
     * lesson is the useful part: the type declaration IS the enforcement, so a test that re-checks it proves
     * nothing. What is NOT type-enforced is the WIRE FORM, so that is what this asserts.
     *
     * Why it matters: JSON has one number type and it is a double. `0.100 TND` — exactly 100 millimes, Tunisia's
     * stamp duty — stops being exact the moment it becomes a float, and a client parsing `19.99` into one has
     * already lost the guarantee `Money` exists to provide. `CLAUDE.md` records money-is-never-a-float as
     * unfixable-later and names upstream's `double` columns as the worst defect in the product twes-in learns from.
     */
    public function testEveryAmountAndQuantityIsAJsonStringRatherThanAJsonNumber(): void
    {
        $json = json_encode($this->represent($this->tndInvoice()), \JSON_THROW_ON_ERROR);

        // The stamp duty, on the wire, QUOTED. An unquoted `0.1` here would be the defect.
        self::assertStringContainsString('"amount":"0.100"', $json, 'the charge amount is a quoted string');

        // And no monetary or quantity field anywhere in the payload is a bare number. Derived from the payload
        // rather than from a list of field names, so a field added later cannot slip through unquoted.
        foreach (['quantity', 'unitNet', 'vatRate', 'net', 'vat', 'amount', 'subtotalNet', 'vatTotal', 'total'] as $field) {
            self::assertDoesNotMatchRegularExpression(
                '/"' . $field . '":[^"]/',
                $json,
                \sprintf('"%s" must be a quoted decimal string, never a JSON number: %s', $field, $json),
            );
        }
    }

    /**
     * PER-LINE VAT IS THE ALLOCATED SHARE, and the shares sum EXACTLY to the group total.
     *
     * The invariant of the largest-remainder rule, and unfixable-later: under `PerRateGroup` the group's VAT is
     * rounded ONCE on the summed base, so the rounded per-line figures do not add up to it on their own and a share
     * must be allocated. Asserting the SUM rather than any individual figure is the right assertion — it is the
     * property, whereas a specific line's share depends on the tie-breaking rule.
     */
    public function testThePerLineVatSharesSumExactlyToTheVatTotal(): void
    {
        $resource = $this->represent($this->tndInvoice());

        $summed = '0';

        foreach ($resource->lines as $line) {
            $summed = bcadd($summed, $line->vat, 6);
        }

        self::assertSame(
            0,
            bccomp($summed, $resource->totals->vatTotal, 6),
            'the allocated per-line shares must sum EXACTLY to the group total — that is the whole rule',
        );
    }

    /**
     * **THE SAME RULE ON A FIXTURE THAT CAN ACTUALLY SEE IT, because `tndInvoice()` cannot.**
     *
     * Round 4's correctness lens replaced `$vatByLine[$position]` with `PriceCalculator::vat($net, $rate, MODE)` —
     * recomputing each line's VAT instead of taking its ALLOCATED share, which is exactly the defect the
     * largest-remainder rule exists to prevent — and **both suites stayed green**. Not an equivalent mutant: it is a
     * different set of numbers on the wire. The reason nothing saw it is that `tndInvoice()` has **zero allocation
     * remainder**, so the allocated and the recomputed shares are byte-identical there and the assertion above cannot
     * distinguish two implementations. Its own comment anticipates a DIFFERENT vacuity (one line per rate group) and
     * misses this one — the fixture-cannot-express-the-dangerous-shape rule (`CLAUDE.md` § Gotchas 2026-07-29)
     * arriving through an arithmetic coincidence rather than a missing role.
     *
     * Two TND lines of `0.013` at 19 % is the smallest fixture with a remainder: the group base is `0.026`, whose VAT
     * rounds to `0.005`, while each line recomputes to `0.002` — so recomputing gives `[0.002, 0.002]` summing to
     * `0.004`, which is a **millime of tax that belongs to the document and appears on no line**.
     *
     * The EXACT shares are asserted here, not only their sum. The case above is right to assert the property rather
     * than a tie-break-dependent figure; this one is the complement, and it pins the tie-break too — ties go to the
     * EARLIEST line, so the extra millime is on line 0. Both remainders are equal here, which is what makes this a
     * tie at all.
     */
    public function testThePerLineVatIsTheAllocatedShareAndNotARecomputation(): void
    {
        $resource = $this->represent($this->remainderInvoice());

        self::assertSame(
            ['0.003', '0.002'],
            array_map(static fn($line): string => $line->vat, $resource->lines),
            'the per-line VAT must be the ALLOCATED share; [0.002, 0.002] is what recomputing net × rate produces, '
            . 'and it loses a millime the document owes',
        );
        self::assertSame('0.005', $resource->totals->vatTotal);
    }

    /** A draft carries neither half of a number; an issued document carries both. */
    public function testADraftHasNoNumberAndAnIssuedDocumentHasBoth(): void
    {
        $draft = $this->represent($this->tndInvoice());
        self::assertNull($draft->number, 'rendered number');
        self::assertNull($draft->sequence, 'sequence');
        self::assertSame('draft', $draft->state);

        $issued = $this->represent(
            $this->tndInvoice()->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41)),
        );
        self::assertSame('0000041', $issued->number, 'the PERSISTED rendered string, printable verbatim');
        self::assertSame(41, $issued->sequence, 'the sequence, which identifies');
        self::assertSame('issued', $issued->state);
    }

    /**
     * THE ROUNDING POINT IS SERVED, because it is persisted per document.
     *
     * A company changing its setting must not restate a document a client already holds, so the value travels with
     * the document — and a client that re-computes a total to preview it needs to know which rule to apply.
     */
    public function testTheRoundingPointTravelsWithTheDocument(): void
    {
        self::assertSame(
            'per_line',
            $this->represent($this->tndInvoice(), VatRoundingPoint::PerLine)->totals->vatRoundingPoint,
        );
        self::assertSame(
            'per_rate_group',
            $this->represent($this->tndInvoice())->totals->vatRoundingPoint,
        );

        // **AND IT GOVERNS THE FIGURES, NOT ONLY THE LABEL.** Round 4's correctness lens hardcoded
        // `VatRoundingPoint::PerRateGroup` for the COMPUTATION while leaving the emitted field reading the
        // identity, and both suites stayed green — so a `per_line` document would have been served with
        // `per_rate_group` numbers while declaring `per_line`. The docblock above says the value exists because
        // "a client that re-computes a total to preview it needs to know which rule to apply": that makes the label
        // a CLAIM ABOUT THE FIGURES, and asserting only the label asserts only that we can spell it.
        //
        // `tndInvoice()` cannot see this either — it has no allocation remainder, so both points give identical
        // numbers. Same root cause as the recomputation mutant, and one fixture kills both.
        $perRateGroup = $this->represent($this->remainderInvoice());
        $perLine = $this->represent($this->remainderInvoice(), VatRoundingPoint::PerLine);

        self::assertSame(['0.005', '0.031'], [$perRateGroup->totals->vatTotal, $perRateGroup->totals->total]);
        self::assertSame(['0.004', '0.030'], [$perLine->totals->vatTotal, $perLine->totals->total]);
    }

    /**
     * **THE ROUNDING MODE IS `HalfUp`, ASSERTED ON A DIGIT THAT ONLY `HalfUp` PRODUCES.**
     *
     * `InvoiceRepresentation::MODE` decided every amount on the wire and survived **all eight** rounding modes with the
     * suite green — including `Up` and `Ceiling`, which move a figure upward, so this was not a case of the fixtures
     * being rounding-free. `RoundingMode::Unnecessary` proved that directly: nine cases errored, meaning nine fixtures
     * do reach a value that requires rounding and not one assertion in the tree looked at the resulting digits. Every
     * existing assertion is mode-INVARIANT by construction — is it a string, does create equal fetch, do the shares
     * sum to the total.
     *
     * So the vector is chosen to be the one thing a mode-invariant assertion cannot be: an exact TIE.
     * `0.010 × 5% = 0.0005` in TND, which has three decimals, so the third decimal is decided purely by the mode —
     * `half_up` gives `0.001`, while `down`, `floor` and `half_even` all give `0.000` and `up`/`ceiling` would give
     * `0.001` on the *positive* side but differ from `half_up` on a negative amount Wave 2's credit note will reach.
     * `docs/spec/pricing-vectors.json` § `conventions.rates` mandates *"half_up on every amount and every rate. There
     * is NO per-case override"*, so this pins the API to the cross-tier convention rather than to a local preference.
     */
    public function testTheWireRoundsHalfUpOnAnExactTie(): void
    {
        $tnd = Currency::of('TND');
        // ONE line, at a rate and quantity whose VAT lands exactly on half a millime.
        $invoice = Invoice::draft($tnd)->withClient(self::FIXTURE_CLIENT)
            ->withLine(new DocumentLine('1', Money::of('0.010', $tnd), Rate::fromPercentage('5')));

        $resource = $this->represent($invoice);

        self::assertSame(
            '0.001',
            $resource->totals->vatTotal,
            'an exact tie must round HALF UP: 0.010 at 5% is 0.0005 TND, and truncation or half-even would give 0.000',
        );

        // AND THE OTHER DIRECTION, which is what distinguishes `HalfUp` from `Up` and `Ceiling`. On a positive tie all
        // three agree, so the assertion above kills `Down`, `Floor` and `HalfEven` and leaves those two alive
        // [measured]. Below the tie they diverge: 0.010 at 1% is 0.0001 TND, which `half_up` takes to 0.000 while
        // `up` and `ceiling` take to 0.001. Two vectors, one per side of the midpoint, is what closes all eight modes.
        $belowTheTie = $this->represent(
            Invoice::draft($tnd)->withClient(self::FIXTURE_CLIENT)->withLine(new DocumentLine('1', Money::of('0.010', $tnd), Rate::fromPercentage('1'))),
        );

        self::assertSame(
            '0.000',
            $belowTheTie->totals->vatTotal,
            'below the tie must round DOWN: 0.010 at 1% is 0.0001 TND, and `up` or `ceiling` would give 0.001. '
            . 'Together with the tie above, these are the only assertions in the suite that see the rounding mode.',
        );
    }

    /**
     * A MISSING DOCUMENT IS A 404 — and so is one belonging to ANOTHER TENANT, indistinguishably.
     *
     * That is the design of row-level security rather than a limitation of it: an error naming the document would
     * confirm its existence to a tenant not entitled to know it exists.
     */
    public function testAnAbsentOrForeignDocumentIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('No such invoice.');

        new InvoiceProvider($this->repositoryReturning(null), $this->scope)
            ->provide(new Get(), ['id' => self::DOCUMENT]);
    }

    /**
     * AN ILL-FORMED ID IS ALSO A 404, NOT A 400, and that is deliberate rather than lazy.
     *
     * Distinguishing "malformed" from "absent" tells an unauthenticated prober that its guess had the right SHAPE,
     * which is a small existence oracle for free. Both answers are "no such document".
     */
    public function testAnIllFormedIdIsAlsoNotFoundRatherThanABadRequest(): void
    {
        $repository = new class implements InvoiceRepository {
            public function save(DocumentIdentity $identity, Invoice $invoice): void
            {
                throw new \LogicException('not under test');
            }

            public function find(string $id): ?PersistedInvoice
            {
                throw new \InvalidArgumentException('A document id must be a canonical lowercase-hyphenated UUID.');
            }

            public function findForMutation(string $id): ?PersistedInvoice
            {
                throw new \LogicException('The read path must not lock for mutation.');
            }
        };

        $this->expectException(NotFoundHttpException::class);

        new InvoiceProvider($repository, $this->scope)->provide(new Get(), ['id' => 'NOT-A-UUID']);
    }

    /**
     * **A CORRUPT ROW IS NOT A 404 — the document exists and something is wrong with OUR data.**
     *
     * The provider used to wrap the whole lookup in `catch (\InvalidArgumentException)` in order to answer a malformed
     * id, and that catch was wide enough to swallow a HYDRATION failure: an amount that no longer parses, a currency
     * code no longer known, any of the mapper's own refusals. So a document that demonstrably EXISTS answered
     * `404 No such invoice.`, the client was told it was absent, and the only trace was a 404 indistinguishable from
     * millions of legitimate ones. Nobody was ever told to investigate.
     *
     * With the id checked up front by `DocumentIdentity::isWellFormedId()` — the one definition of that rule — the
     * catch is gone and this propagates, which the transport turns into a 500 and `error.internal` per `CLAUDE.md`
     * § "Translation keys": our own fault is not the client's to interpret.
     *
     * The id here is WELL FORMED, which is the whole point. A malformed one never reaches `find()` any more, so this
     * case and `testAnIllFormedIdIsAlsoNotFoundRatherThanABadRequest()` now exercise genuinely different paths — and
     * the mutant is direct: re-wrap the lookup in that catch and this case goes red while the other stays green.
     */
    public function testACorruptRowPropagatesRatherThanAnsweringNotFound(): void
    {
        $repository = new class implements InvoiceRepository {
            public function save(DocumentIdentity $identity, Invoice $invoice): void
            {
                throw new \LogicException('not under test');
            }

            public function find(string $id): ?PersistedInvoice
            {
                // What the mapper raises for a stored amount that is no longer a well-formed decimal.
                throw new \InvalidArgumentException('An amount must be a decimal string, got "1.2.3".');
            }

            public function findForMutation(string $id): ?PersistedInvoice
            {
                throw new \LogicException('The read path must not lock for mutation.');
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An amount must be a decimal string');

        new InvoiceProvider($repository, $this->scope)->provide(new Get(), ['id' => self::DOCUMENT]);
    }

    /** A non-string route value is a 404 too, not a TypeError reaching the client as a 500. */
    public function testANonStringIdIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        new InvoiceProvider($this->repositoryReturning(null), $this->scope)->provide(new Get(), ['id' => 42]);
    }

    private function tndInvoice(): Invoice
    {
        $tnd = Currency::of('TND');

        // TWO LINES AT THE SAME RATE, which is what makes the allocation case real: one line per rate group would
        // make the allocated share trivially equal to the group total and the sum assertion vacuous.
        return Invoice::draft($tnd)->withClient(self::FIXTURE_CLIENT)
            ->withLine(new DocumentLine('3', Money::of('1.234', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('7', Money::of('0.567', $tnd), Rate::fromPercentage('19')))
            ->withFixedCharge(new FixedCharge('stamp_duty', Money::of('0.100', $tnd)));
    }

    /**
     * The smallest invoice whose VAT allocation carries a REMAINDER, which `tndInvoice()` does not.
     *
     * Two TND lines of `0.013` at 19 %. Group base `0.026` → VAT `0.00494` → `0.005`. Each line alone recomputes to
     * `0.00247` → `0.002`, so the two rounded per-line figures sum to `0.004` and a millime has to be ALLOCATED.
     * [Verified: `per_rate_group` → `vatTotal=0.005 total=0.031 perLine=[0.003, 0.002]`; `per_line` →
     * `vatTotal=0.004 total=0.030 perLine=[0.002, 0.002]`.]
     *
     * **Separate from `tndInvoice()` rather than replacing it.** That fixture is used by six other cases whose
     * subjects are unrelated (numbers, states, string encoding, 404s), and widening it would make each of them
     * depend on allocation arithmetic they do not care about. This one exists to be the case where the two rules
     * DISAGREE, which is the only place either can be pinned.
     */
    private function remainderInvoice(): Invoice
    {
        $tnd = Currency::of('TND');

        return Invoice::draft($tnd)->withClient(self::FIXTURE_CLIENT)
            ->withLine(new DocumentLine('1', Money::of('0.013', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('1', Money::of('0.013', $tnd), Rate::fromPercentage('19')));
    }

    private function represent(
        Invoice $invoice,
        VatRoundingPoint $point = VatRoundingPoint::PerRateGroup,
    ): InvoiceResource {
        $identity = new DocumentIdentity(self::DOCUMENT, DocumentType::Invoice, $point);

        return new InvoiceProvider($this->repositoryReturning(new PersistedInvoice($identity, $invoice)), $this->scope)
            ->provide(new Get(), ['id' => self::DOCUMENT]);
    }

    private function repositoryReturning(?PersistedInvoice $persisted): InvoiceRepository
    {
        return new class ($persisted) implements InvoiceRepository {
            public function __construct(private readonly ?PersistedInvoice $persisted) {}

            public function save(DocumentIdentity $identity, Invoice $invoice): void
            {
                throw new \LogicException('The read path must not save.');
            }

            public function find(string $id): ?PersistedInvoice
            {
                return $this->persisted;
            }

            /**
             * A REFUSAL, not a delegation, and it is an assertion in disguise.
             *
             * `findForMutation()` takes a row lock that lasts until the transaction commits. A read endpoint must
             * never take one — every reader would then queue behind every writer, for a guarantee no caller asked
             * for — so a provider that reached for it is a defect, and this is what makes that defect a failing test
             * instead of a latency regression nobody can attribute.
             */
            public function findForMutation(string $id): ?PersistedInvoice
            {
                throw new \LogicException('The read path must not lock for mutation.');
            }
        };
    }
}
