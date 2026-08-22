<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Application\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Application\Document\CreateInvoice;
use Twes\Application\Document\CreateInvoiceHandler;
use Twes\Application\Document\IssueInvoice;
use Twes\Application\Document\IssueInvoiceHandler;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\DocumentNumberAllocator;
use Twes\Domain\Document\DocumentState;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\Exception\DocumentCannotBeIssued;
use Twes\Domain\Document\Exception\IllegalTransition;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Tests\Support\FixedIdGenerator;
use Twes\Tests\Support\InMemoryCompanySettingsRepository;
use Twes\Tests\Support\InMemoryDocumentNumberSequence;
use Twes\Tests\Support\InMemoryInvoiceRepository;
use Twes\Tests\Support\RecordingTransactionalScope;

/**
 * THE TWO WRITE USE CASES — the decisions the `Application/` layer owns and nothing else does.
 *
 * **The first content of `tests/Unit/Application/`**, because `Application/` was empty until the invoice write path
 * landed. `scripts/gates/layer-dependencies.php` now requires that layer to be non-empty for the reason its own
 * comment gives: an empty layer is otherwise indistinguishable from a passing one.
 *
 * **WHAT IS UNDER TEST AND WHAT IS NOT.** These handlers hold no business rules — every refusal below comes from the
 * aggregate, and the arithmetic comes from `DocumentCalculator`. What they own is *orchestration*, and each of the
 * four things they own can be got wrong silently:
 *
 * 1. **The transaction boundary.** One scope covering the allocation and the write. A handler that opened none, or
 *    two, would work in a single-threaded test and lose atomicity in production — so `RecordingTransactionalScope`
 *    reports it rather than passing the closure through invisibly.
 * 2. **Where the id comes from.** The server, never the caller.
 * 3. **Where the NUMBER comes from.** The allocator, never `new DocumentNumber(...)` — `build-waves.plan.md` records
 *    a handler constructing one directly as a `completeness-reviewer` **P0**, because `Invoice::issue()` accepts any
 *    well-typed number (correctly, for rehydration) and therefore cannot enforce this itself.
 * 4. **The order of operations.** Find, then allocate, then issue, then save.
 *
 * Driven through the domain ports with in-memory doubles rather than mocks: the doubles implement the real interfaces,
 * so PHP refuses to compile a test that has drifted from a port. See `InMemoryInvoiceRepository` for what its fake
 * deliberately does NOT simulate and where those properties are asserted instead.
 */
#[CoversClass(CreateInvoiceHandler::class)]
#[CoversClass(IssueInvoiceHandler::class)]
#[CoversClass(CreateInvoice::class)]
#[CoversClass(IssueInvoice::class)]
final class InvoiceWriteHandlersTest extends TestCase
{
    /**
     * Every invoice fixture that gets ISSUED is addressed to a client, because since 2026-08-22
     * `Invoice::issue()` requires one — EN 16931 makes the buyer mandatory (BT-44). The deliberately
     * EMPTY command below keeps NO client: its subject is the emptiness refusal, and giving it one
     * would change which guard the case proves.
     */
    private const FIXTURE_CLIENT = '0199a5b2-0000-7000-8000-00000000c101';

    private const FIRST_ID = '0199a5b2-0000-7000-8000-000000000001';

    // ------------------------------------------------------------------ create

    /** A created invoice is a DRAFT with the lines it was given, an id from the generator, and NO number. */
    public function testCreatingAnInvoiceProducesAnUnnumberedDraft(): void
    {
        $repository = new InMemoryInvoiceRepository();
        $ids = new FixedIdGenerator();

        $created = self::creator($repository, $ids)->handle(self::command());

        self::assertSame(self::FIRST_ID, $created->identity->id, 'the id comes from the generator port');
        self::assertSame([self::FIRST_ID], $ids->handedOut, 'and the handler asked for exactly one');
        self::assertSame(DocumentType::Invoice, $created->identity->type);
        self::assertSame(DocumentState::Draft, $created->invoice->state());
        self::assertNull(
            $created->invoice->number(),
            'a draft carries NO number: allocating at create would let every abandoned draft consume one from a '
            . 'gapless legal sequence, permanently',
        );
        self::assertCount(2, $created->invoice->lines());
        self::assertCount(1, $created->invoice->fixedCharges());
    }

    /**
     * THE WRITE HAPPENS IN EXACTLY ONE TRANSACTION, and the aggregate is built OUTSIDE it.
     *
     * Outside, because every refusal the aggregate raises while being built is a caller error that touches no rows —
     * opening a transaction first would mean opening and rolling one back for every invalid request, and would hold it
     * open across the `totals()` computation `withLine()` performs on each call.
     *
     * Exactly one, because DBAL implements a nested `beginTransaction()` as a SAVEPOINT: a handler that wrapped its
     * writes in two scopes would look atomic here and, in production, be two units of work whose second half can roll
     * back alone.
     */
    public function testCreatingAnInvoiceUsesExactlyOneTransaction(): void
    {
        $scope = new RecordingTransactionalScope();
        $repository = new InMemoryInvoiceRepository();

        new CreateInvoiceHandler($repository, new FixedIdGenerator(), $scope, new InMemoryCompanySettingsRepository())->handle(self::command());

        self::assertSame(1, $scope->entered, 'one scope, not zero and not two');
        self::assertSame(1, $scope->maxDepth, 'and it is flat — a nested scope is a savepoint, not a transaction');
        self::assertSame(1, $repository->saves, 'exactly one write');
    }

    /**
     * THE RESULT IS THE DOCUMENT AS READ BACK, not the aggregate that was just built.
     *
     * The reason is a contract one: `quantity` is `NUMERIC(21,6)`, so a line written as `2` is read back as
     * `2.000000` — the same number, a different string — and a create response that does not match what a subsequent
     * `GET` returns is a defect whether or not anyone compares by string, because the mobile client freezes the
     * contract on app-store timelines.
     *
     * The in-memory double cannot re-scale anything, so what this case can prove is the STRUCTURE: the value returned
     * is the one that came out of `find()`. `InvoiceLifecycleTest` proves the byte-for-byte property against real
     * columns. Stated rather than left implied, because a test that looks like it covers re-scaling and does not is
     * worse than one that admits it.
     */
    public function testTheCreateResultComesFromTheRepositoryRatherThanFromMemory(): void
    {
        $repository = new InMemoryInvoiceRepository();

        $created = self::creator($repository)->handle(self::command());

        self::assertSame(
            $repository->find(self::FIRST_ID),
            $created,
            'the returned pair must be the one `find()` produced — a re-read inside the same transaction',
        );
    }

    /** A line in another currency is refused by the aggregate, and NOTHING is written. */
    public function testAForeignCurrencyLineIsRefusedAndNothingIsWritten(): void
    {
        $repository = new InMemoryInvoiceRepository();
        $scope = new RecordingTransactionalScope();

        $eur = Currency::of('EUR');
        $command = new CreateInvoice(
            Currency::of('TND'),
            [new DocumentLine('1', Money::of('1.00', $eur), Rate::fromPercentage('19'))],
            [],
            self::FIXTURE_CLIENT,
        );

        try {
            new CreateInvoiceHandler($repository, new FixedIdGenerator(), $scope, new InMemoryCompanySettingsRepository())->handle($command);
            self::fail('a line in another currency must be refused');
        } catch (CurrencyMismatch) {
            // Expected.
        }

        self::assertSame(0, $repository->saves, 'nothing written');
        self::assertSame(
            0,
            $scope->entered,
            'and no transaction was even opened — the aggregate is built before the scope, so an invalid request '
            . 'costs no round trip to the database',
        );
    }

    // ------------------------------------------------------------------ issue

    /** Issuing allocates the number from the ALLOCATOR and renders it at the configured width. */
    public function testIssuingAllocatesFromTheSequenceAndRendersAtTheConfiguredWidth(): void
    {
        $repository = new InMemoryInvoiceRepository();
        self::creator($repository)->handle(self::command());

        $issued = self::issuer($repository)->handle(new IssueInvoice(self::FIRST_ID));

        self::assertNotNull($issued);
        self::assertSame(DocumentState::Issued, $issued->invoice->state());

        $number = $issued->invoice->number();
        self::assertNotNull($number, 'an issued document carries its number');
        self::assertSame(1, $number->sequence(), 'the first number of this tenant\'s life is 1');
        self::assertSame('0000001', $number->number(), 'rendered at the injected width of 7');
        self::assertSame(DocumentType::Invoice, $number->type(), 'from the INVOICE sequence, not another type\'s');
    }

    /**
     * THE NUMBER COMES FROM THE SEQUENCE PORT — asserted by consuming from the same counter behind the handler's back.
     *
     * This is the case that makes item 3 of the class docblock real. `Invoice::issue()` accepts any well-typed
     * `DocumentNumber`, so a handler could satisfy every other assertion here by doing
     * `new DocumentNumber(DocumentType::Invoice, $pattern, 1)` and never touching the counter at all — and
     * `build-waves.plan.md` records exactly that as a **P0**. Pre-consuming two values means the handler must come
     * back with 3; a handler minting its own would produce 1.
     */
    public function testTheIssuedNumberIsTakenFromTheSequenceAndNotMinted(): void
    {
        $repository = new InMemoryInvoiceRepository();
        self::creator($repository)->handle(self::command());

        $sequence = new InMemoryDocumentNumberSequence();
        $sequence->allocateNext(DocumentType::Invoice);
        $sequence->allocateNext(DocumentType::Invoice);

        $issued = self::issuer($repository, $sequence)->handle(new IssueInvoice(self::FIRST_ID));

        self::assertNotNull($issued);
        self::assertSame(
            3,
            $issued->invoice->number()?->sequence(),
            'the handler must CONSUME from the counter, not mint a number of its own — a minted number would be 1 '
            . 'here and would duplicate whatever the counter hands out next',
        );
    }

    /** Issuing is one transaction covering the allocation AND the write — the reason the scope exists at all. */
    public function testIssuingUsesExactlyOneTransactionCoveringBothTheNumberAndTheWrite(): void
    {
        $repository = new InMemoryInvoiceRepository();
        self::creator($repository)->handle(self::command());
        $writesBefore = $repository->saves;

        $scope = new RecordingTransactionalScope();
        new IssueInvoiceHandler($repository, self::allocator(), $scope, InMemoryCompanySettingsRepository::withNumberWidth(7))->handle(new IssueInvoice(self::FIRST_ID));

        self::assertSame(1, $scope->entered, 'one scope for the allocation and the write together');
        self::assertSame(1, $scope->maxDepth, 'flat');
        self::assertSame($writesBefore + 1, $repository->saves, 'and it wrote once');
    }

    /**
     * ISSUING READS THE DOCUMENT WITH `findForMutation()`, NOT `find()` — and it does so BEFORE allocating.
     *
     * Both halves are asserted because both are the defect. With a plain read, two concurrent issues of one draft
     * each see `draft`, each allocate (the counter serialises them, so 1 and 2), each build an issued aggregate from
     * their own stale snapshot, and the second save overwrites the first: the document is numbered 2 while **number
     * 1 is allocated to no document at all**, and the client that already received a 200 for invoice 1 finds it
     * renumbered. A hole in an invoice sequence is what a tax authority reads as a suppressed sale, which is the
     * whole reason § Gotchas 2026-07-31 forbids `nextval()`.
     *
     * **WHAT THIS CASE CAN AND CANNOT SEE, stated so it is not mistaken for the proof.** An in-memory fake with one
     * thread has nothing to serialise, so the GUARANTEE is unobservable here; the two-transaction proof against a
     * real row lock is `DoctrineInvoiceRepositoryTest`, and the end-to-end no-hole property is
     * `InvoiceLifecycleTest`. What is observable here — and nowhere else — is that the handler ASKED, and in the
     * right order relative to the allocation. That is the half a change to this one line would break, and without
     * this case the change is invisible: both reads return the same document.
     */
    public function testIssuingTakesTheDocumentForMutationBeforeItAllocatesANumber(): void
    {
        $repository = new InMemoryInvoiceRepository();
        self::creator($repository)->handle(self::command());

        $sequence = new InMemoryDocumentNumberSequence();
        $heldWhenAllocated = null;
        $sequence->beforeAllocating = static function () use ($repository, &$heldWhenAllocated): void {
            $heldWhenAllocated = \count($repository->mutatingReads);
        };

        new IssueInvoiceHandler($repository, new DocumentNumberAllocator($sequence), new RecordingTransactionalScope(), InMemoryCompanySettingsRepository::withNumberWidth(7))
            ->handle(new IssueInvoice(self::FIRST_ID));

        self::assertSame(
            [self::FIRST_ID],
            $repository->mutatingReads,
            'the issue transition must hold the document, so it reads with findForMutation() exactly once',
        );

        // AND THE ORDER, sampled at the instant the number is taken. Allocating first would leave this at 0, and the
        // document would then be held only after the number it is trying not to waste had already been consumed.
        self::assertSame(
            1,
            $heldWhenAllocated,
            'the document is held BEFORE the counter is touched — one lock order for every writer, document then '
            . 'counter, which is also what makes a deadlock between two issues impossible rather than unlikely',
        );
    }

    /** An unknown id is `null` — not an exception — so the transport can answer 404 without distinguishing why. */
    public function testIssuingAnUnknownInvoiceReturnsNull(): void
    {
        self::assertNull(
            self::issuer(new InMemoryInvoiceRepository())->handle(new IssueInvoice(self::FIRST_ID)),
            'absent and belonging-to-another-tenant must be indistinguishable — an error naming the document would '
            . 'confirm its existence to a tenant not entitled to know',
        );
    }

    /** Issuing twice is refused by the transition guard, and the second attempt consumes no number. */
    public function testIssuingTwiceIsRefusedAndConsumesNoSecondNumber(): void
    {
        $repository = new InMemoryInvoiceRepository();
        self::creator($repository)->handle(self::command());

        $sequence = new InMemoryDocumentNumberSequence();
        $issuer = self::issuer($repository, $sequence);

        $issuer->handle(new IssueInvoice(self::FIRST_ID));

        try {
            $issuer->handle(new IssueInvoice(self::FIRST_ID));
            self::fail('issuing an already-issued invoice must be refused');
        } catch (IllegalTransition) {
            // Expected: a 422 at the transport, because the caller's page is stale and reloading shows the truth.
        }

        // **THE SECOND ATTEMPT DID CONSUME A NUMBER, AND THAT IS CORRECT HERE — read this before "fixing" it.**
        //
        // `issue()` is what refuses, and it is called with the allocated number as its argument, so the allocation
        // happens first. In production the transaction rolls it back and nothing is lost. `RecordingTransactionalScope`
        // rolls back NOTHING — it cannot, since undoing an in-memory counter would mean the double reimplementing a
        // database — so here the counter really has advanced twice, and asserting otherwise would be asserting a
        // property this fixture does not have. The first version of this case asserted 2 and justified it by "the
        // rollback", which is a test claiming coverage its own double cannot provide.
        //
        // Where the rollback property IS proven, against a real transaction:
        // `PostgresDocumentNumberSequenceTest::testARolledBackAllocationReturnsTheNumberRatherThanBurningIt()` and
        // `InvoiceLifecycleTest::testAFailedIssueLeavesNoHoleInTheSequence()`.
        //
        // **The alternative was to check the transition BEFORE allocating, and it was rejected**: the handler would
        // then hold its own copy of "which states may be issued", and `Invoice::assertMutable()`'s comment requires
        // that rule to have exactly one definition — two copies diverge the day a state is added.
        self::assertSame(
            3,
            $sequence->allocateNext(DocumentType::Invoice),
            'both attempts allocated, because the aggregate refuses AFTER the number is handed to `issue()` — the '
            . 'transaction is what returns it, and this double has no transaction',
        );
    }

    /** An invoice with no lines cannot be issued — a number would be spent on a document with no content. */
    public function testAnEmptyInvoiceCannotBeIssued(): void
    {
        $repository = new InMemoryInvoiceRepository();
        $empty = new CreateInvoice(Currency::of('TND'), [], []);
        self::creator($repository)->handle($empty);

        $this->expectException(DocumentCannotBeIssued::class);

        self::issuer($repository)->handle(new IssueInvoice(self::FIRST_ID));
    }

    // ------------------------------------------------------------------ fixtures

    private static function command(): CreateInvoice
    {
        $tnd = Currency::of('TND');

        // TND, three decimals, because CLAUDE.md § Architecture: "a 2-decimal assumption is a bug for the default
        // currency, not an edge case". Two lines at one rate so the VAT allocation is not trivially the group total.
        return new CreateInvoice(
            $tnd,
            [
                new DocumentLine('3', Money::of('1.234', $tnd), Rate::fromPercentage('19')),
                new DocumentLine('7', Money::of('0.567', $tnd), Rate::fromPercentage('19')),
            ],
            [new FixedCharge('stamp_duty', Money::of('0.100', $tnd))],
            self::FIXTURE_CLIENT,
        );
    }

    private static function creator(
        InMemoryInvoiceRepository $repository,
        ?FixedIdGenerator $ids = null,
    ): CreateInvoiceHandler {
        return new CreateInvoiceHandler(
            $repository,
            $ids ?? new FixedIdGenerator(),
            new RecordingTransactionalScope(),
            new InMemoryCompanySettingsRepository(),
        );
    }

    private static function issuer(
        InMemoryInvoiceRepository $repository,
        ?InMemoryDocumentNumberSequence $sequence = null,
    ): IssueInvoiceHandler {
        return new IssueInvoiceHandler(
            $repository,
            self::allocator($sequence),
            new RecordingTransactionalScope(),
            // Width 7, which is `CompanySettings::defaults()`. It used to be a literal `7` "matching
            // `services.yaml`"; that parameter is gone, and the comment explaining it went with it. Stated
            // explicitly rather than by calling `defaults()` so that a change to the default shows up here as a
            // failure to explain rather than as a silently different expectation.
            InMemoryCompanySettingsRepository::withNumberWidth(7),
        );
    }

    private static function allocator(?InMemoryDocumentNumberSequence $sequence = null): DocumentNumberAllocator
    {
        return new DocumentNumberAllocator($sequence ?? new InMemoryDocumentNumberSequence());
    }

    /** The configured width really is what renders the number — a guard against 7 being coincidental. */
    public function testTheConfiguredWidthIsWhatRendersTheNumber(): void
    {
        $repository = new InMemoryInvoiceRepository();
        self::creator($repository)->handle(self::command());

        $issued = new IssueInvoiceHandler($repository, self::allocator(), new RecordingTransactionalScope(), InMemoryCompanySettingsRepository::withNumberWidth(3))
            ->handle(new IssueInvoice(self::FIRST_ID));

        self::assertSame('001', $issued?->invoice->number()?->number(), 'width 3 renders 001, not 0000001');
    }

    /**
     * THE WIDTH IS READ PER CALL, NOT FROZEN WHEN THE HANDLER IS BUILT.
     *
     * **This replaced `testAnImpossibleConfiguredWidthFailsAtConstruction()`, and the swap is a real change in
     * where a guarantee lives rather than a rename.** That case asserted that a width `NumberPattern` refuses blows
     * up when the service is constructed — which was true while the width was a container parameter, and is
     * meaningless now that it comes from a row. The guarantee moved to the schema: `company_settings
     * .number_pattern_width` carries `CHECK (BETWEEN 1 AND NumberPattern::MAX_WIDTH)`, so an impossible width
     * cannot be stored at all, and `ConfiguredSettingsAreHonouredTest` proves the database refuses one. That is
     * strictly stronger — a constructor check guards one call site, a constraint guards every writer including
     * `psql`.
     *
     * What is worth asserting HERE instead is the property the move created: two issues by ONE handler instance
     * see two different widths when the settings change between them. A handler that cached the pattern — the
     * shape the old code had — passes every other case in this class and fails this one.
     */
    public function testTheWidthIsResolvedOnEveryIssueRatherThanCachedOnce(): void
    {
        $repository = new InMemoryInvoiceRepository();
        $settings = InMemoryCompanySettingsRepository::withNumberWidth(3);
        $handler = new IssueInvoiceHandler(
            $repository,
            self::allocator(),
            new RecordingTransactionalScope(),
            $settings,
        );

        // ONE generator across both drafts, because `FixedIdGenerator` counts rather than taking an id: two separate
        // instances would each hand out `…001` and the second create would collide with the first document.
        $ids = new FixedIdGenerator();
        $creator = self::creator($repository, $ids);

        $creator->handle(self::command());
        $first = $handler->handle(new IssueInvoice($ids->handedOut[0]));

        $settings->save($settings->forCurrentTenant()->withNumberPattern(NumberPattern::padded(6)));

        $creator->handle(self::command());
        $second = $handler->handle(new IssueInvoice($ids->handedOut[1]));

        self::assertSame('001', $first?->invoice->number()?->number(), 'the first issue renders at the width then configured');
        self::assertSame(
            '000002',
            $second?->invoice->number()?->number(),
            'the second must pick up the NEW width — the same handler instance, so a cached NumberPattern would '
            . 'render 002 here and this assertion is what forbids caching it',
        );
    }
}
