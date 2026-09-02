<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration\Document;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Application\Document\CreateInvoice;
use Twes\Application\Document\CreateInvoiceHandler;
use Twes\Application\Document\IssueInvoice;
use Twes\Application\Document\IssueInvoiceHandler;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\DocumentNumberAllocator;
use Twes\Domain\Document\DocumentState;
use Twes\Domain\Document\Exception\DocumentCannotBeIssued;
use Twes\Domain\Document\Exception\UnknownClient;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\IdGenerator;
use Twes\Infrastructure\Persistence\Doctrine\DbalTransactionalScope;
use Twes\Infrastructure\Persistence\Doctrine\DoctrineCompanySettingsRepository;
use Twes\Infrastructure\Persistence\Doctrine\DoctrineInvoiceRepository;
use Twes\Infrastructure\Persistence\Doctrine\InvoiceMapper;
use Twes\Infrastructure\Persistence\Doctrine\PostgresDocumentNumberSequence;
use Twes\Infrastructure\Shared\UuidV7Generator;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Tests\Integration\Tenancy\MigratedProbeDatabase;
use Twes\UI\Http\State\InvoiceRepresentation;

/**
 * THE WHOLE WRITE PATH, ASSEMBLED FROM THE REAL PARTS: create, then issue, against a migrated schema.
 *
 * **This is the only test in the suite with no doubles in it at all** — the real repository, the real gapless counter,
 * the real DBAL transaction, the real UUIDv7 generator, the real aggregate. `InvoiceWriteHandlersTest` covers the
 * handlers' orchestration with in-memory ports, which is the right tool for "did it open one transaction"; it is
 * structurally blind to everything a real column and a real rollback do, and that blindness is what this class exists
 * to cover.
 *
 * Three properties are asserted here and NOWHERE ELSE, each because a double cannot express it:
 *
 * 1. **A create response is byte-for-byte what a later fetch returns.** `quantity` is `NUMERIC(21,6)`, so a line
 *    written as `3` comes back as `3.000000`. The handlers re-read inside the write transaction precisely so the two
 *    agree; without real columns there is nothing to re-scale and the assertion would be vacuous.
 * 2. **A failed issue leaves NO HOLE in the number sequence.** This is the property the whole design of the counter
 *    exists for and the reason `nextval()` is forbidden — and it needs a real transaction to roll back, which an
 *    in-memory scope has no way to model.
 * 3. **Issuing consumes consecutive numbers across separate committed transactions.** The counter's gaplessness is
 *    contract-tested inside one transaction; this is the cross-transaction shape a production sequence actually meets.
 */
#[CoversClass(CreateInvoiceHandler::class)]
#[CoversClass(IssueInvoiceHandler::class)]
#[CoversClass(DbalTransactionalScope::class)]
final class InvoiceLifecycleTest extends TestCase
{
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_invoice_lifecycle_probe';
    private const TENANT = '0199a5b2-0000-7000-8000-0000000004aa';

    /**
     * The client every invoice in this class is addressed to.
     *
     * Not optional decoration: since 2026-08-22 `Invoice::issue()` refuses a document with no client, because
     * EN 16931 makes the buyer mandatory (BT-44). Every case here issues, so every case needs one.
     */
    private const FIXTURE_CLIENT = '0199a5b2-0000-7000-8000-00000000c401';

    private static ?Connection $connection = null;

    public static function setUpBeforeClass(): void
    {
        self::createMigratedProbeDatabase(self::DATABASE);
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection = null;
        self::dropProbeDatabase(self::DATABASE);
    }

    protected function setUp(): void
    {
        // A CLEAN SLATE PER CASE, because the counter is the shared state these cases assert on and a leftover row
        // would make "the first number is 1" depend on execution order. Deleted rather than truncated: the runtime
        // role deliberately has no TRUNCATE, and using a privilege production forbids would make the fixture
        // unrepresentative — `BehaviouralIsolationTest` attacks exactly that grant.
        $connection = self::connection();

        // `client` IS LAST, and the order is a fact of the schema rather than a style. `document.client_id` is a
        // foreign key with `ON DELETE RESTRICT` — deleting a client that invoices name is refused, deliberately,
        // because erasing a client must never erase the legal documents addressed to them. So the documents go
        // first and the client follows.
        foreach (['document_line', 'document_charge', 'document', 'document_number_sequence', 'client'] as $table) {
            $connection->executeStatement(\sprintf('DELETE FROM %s', $table));
        }

        self::seedClient();
    }

    /**
     * Put a bare client row where this class's documents can point at it.
     *
     * RAW SQL RATHER THAN THE CLIENT REPOSITORY, for the reason `seedSettings()` gives about its own table: a
     * fixture built with code under test cannot fail independently of it. It also keeps this suite from going red
     * whenever `Client` changes — the client here is a row a foreign key can point at, not a subject.
     */
    private static function seedClient(): void
    {
        self::connection()->executeStatement(
            'INSERT INTO client (company_id, id, name) VALUES (?, ?, ?)',
            [self::TENANT, self::FIXTURE_CLIENT, 'Fixture client'],
        );
    }

    /**
     * CREATE, THEN ISSUE. The happy path, end to end, with the number allocated from the real counter.
     */
    public function testAnInvoiceIsCreatedAsADraftAndThenIssuedWithNumberOne(): void
    {
        $created = self::creator()->handle(self::command());

        self::assertSame(DocumentState::Draft, $created->invoice->state());
        self::assertNull($created->invoice->number(), 'a draft has no number');

        $issued = self::issuer()->handle(new IssueInvoice($created->identity->id));

        self::assertNotNull($issued);
        self::assertSame(DocumentState::Issued, $issued->invoice->state());

        // Narrowed ONCE rather than two nullsafe calls: after the first `assertSame(1, …?->sequence())` PHPStan knows
        // the number cannot be null, so the second `?->` is provably redundant and reads as a check that is not one.
        $number = $issued->invoice->number();
        self::assertNotNull($number, 'an issued document carries its number');
        self::assertSame(1, $number->sequence());
        self::assertSame('0000001', $number->number());

        // AND IT IS ON DISK, both halves of the number in their own columns with the CHECK constraint pairing them.
        $row = self::connection()->fetchAssociative(
            'SELECT state, number, number_rendered FROM document WHERE company_id = ? AND id = ?',
            [self::TENANT, $created->identity->id],
        );

        self::assertSame(
            ['state' => 'issued', 'number' => 1, 'number_rendered' => '0000001'],
            $row,
            'the persisted row carries the sequence and the RENDERED string separately — the rendered one is what a '
            . 'client holds forever, whatever the width setting later becomes',
        );
    }

    /**
     * **A CREATE RESPONSE IS BYTE-FOR-BYTE WHAT A LATER FETCH RETURNS.** Property 1 of the class docblock.
     *
     * The handlers re-read the document inside their own write transaction rather than returning the aggregate they
     * built, and this is the assertion that makes that decision load-bearing: `quantity` is `NUMERIC(21,6)`, so a line
     * written as `3` reads back as `3.000000`. Returning the in-memory aggregate would make `POST` answer `"3"` and a
     * subsequent `GET` answer `"3.000000"` for the same document — the same number, a different string, and a contract
     * a mobile client freezes on app-store timelines.
     *
     * Compared as the SERIALISED payload rather than as objects, because the payload is what the clients see.
     *
     * The mutant: change either handler's `return` to the aggregate it just built and this case fails on `quantity`
     * while every other case in the suite stays green.
     */
    public function testACreateResponseIsIdenticalToWhatALaterFetchReturns(): void
    {
        $created = self::creator()->handle(self::command());

        $fetched = self::repository()->find($created->identity->id);
        self::assertNotNull($fetched);

        self::assertJsonStringEqualsJsonString(
            self::payload(InvoiceRepresentation::of($created)),
            self::payload(InvoiceRepresentation::of($fetched)),
            'the create response and a later GET must be the same bytes for the same document',
        );

        // AND THE SAME AFTER ISSUING, which is the case that matters more: the response now contains the number.
        $issued = self::issuer()->handle(new IssueInvoice($created->identity->id));
        self::assertNotNull($issued);

        $refetched = self::repository()->find($created->identity->id);
        self::assertNotNull($refetched);

        self::assertJsonStringEqualsJsonString(
            self::payload(InvoiceRepresentation::of($issued)),
            self::payload(InvoiceRepresentation::of($refetched)),
            'the issue response and a later GET must agree too',
        );
    }

    /**
     * **A FAILED ISSUE LEAVES NO HOLE IN THE SEQUENCE.** Property 2 — the reason a PostgreSQL `SEQUENCE` is forbidden.
     *
     * An empty invoice cannot be issued, and the aggregate refuses *after* the number has been handed to `issue()` —
     * so the allocation really happened and the transaction is what returns it. With `nextval()` it would not: the
     * number would be burned and the tenant's first real invoice would be number 2, which is a gap a French or
     * Tunisian audit reads as a suppressed sale.
     *
     * `InvoiceWriteHandlersTest` cannot assert this — its scope double rolls nothing back, and its own comment says so
     * rather than pretending otherwise.
     *
     * The mutant, and it had to be constructed carefully to be the right one: making the allocation COMMIT on its own
     * (`commit()` immediately after the upsert, then a fresh `beginTransaction()`) turns this red with
     * `Failed asserting that 2 is identical to 1` — the surviving invoice becomes number 2, which is precisely
     * `nextval()`'s behaviour. [Verified.] Committing *before* the upsert instead does NOT kill it, because the
     * allocation then still rolls back with the enclosing transaction: the property is that the increment must not
     * commit INDEPENDENTLY, not that a commit must never happen nearby.
     */
    public function testAFailedIssueLeavesNoHoleInTheSequence(): void
    {
        $empty = self::creator()->handle(
            new CreateInvoice(Currency::of('TND'), [], []),
        );

        try {
            self::issuer()->handle(new IssueInvoice($empty->identity->id));
            self::fail('an empty invoice must not be issuable — its number would be spent on nothing');
        } catch (DocumentCannotBeIssued) {
            // Expected. The transaction has rolled back by now, and with it the counter.
        }

        $real = self::creator()->handle(self::command());
        $issued = self::issuer()->handle(new IssueInvoice($real->identity->id));

        self::assertSame(
            1,
            $issued?->invoice->number()?->sequence(),
            'the first SUCCESSFULLY issued invoice must be number 1 — a failed attempt must not burn a number, which '
            . 'is precisely what a PostgreSQL SEQUENCE would have done',
        );
    }

    /**
     * **CONSECUTIVE NUMBERS ACROSS SEPARATE COMMITTED TRANSACTIONS.** Property 3.
     *
     * The contract class asserts gaplessness within one transaction, where even a sequence would look gapless. This is
     * the shape production actually meets: three requests, three commits, `1, 2, 3`.
     */
    public function testIssuingSeveralInvoicesNumbersThemConsecutivelyAcrossTransactions(): void
    {
        $allocated = [];

        for ($i = 0; $i < 3; ++$i) {
            $created = self::creator()->handle(self::command());
            $allocated[] = self::issuer()->handle(new IssueInvoice($created->identity->id))
                ?->invoice->number()?->sequence();
        }

        self::assertSame([1, 2, 3], $allocated);
    }

    /**
     * **AN ISSUE THAT CANNOT HOLD THE DOCUMENT ALLOCATES NOTHING — the outcome half of R1-2.**
     *
     * The panel's finding: with an ordinary read, two concurrent issues of one draft both see `draft`, both allocate
     * (the counter serialises them, so 1 and 2), both build an issued aggregate from their own stale snapshot, and the
     * second save overwrites the first. The document ends up numbered 2 while **number 1 is allocated to no document
     * at all** — the permanent hole that § Gotchas 2026-07-31 forbids `nextval()` to avoid — and the client that
     * already received a 200 for invoice 1 finds it renumbered. [Reproduced against this schema with two live
     * transactions before the fix: `allocated=[1,2] on documents=[2]`.]
     *
     * **The real handler is what runs here, and the rival is a bare row lock rather than a second handler.** A second
     * handler would need its transaction held open across `transactional()`, which the port deliberately does not
     * allow; and "another transaction is holding this document" is the real-world CONDITION, so producing it with the
     * statement that produces it is honest, whereas re-implementing the handler's steps beside it would assert a copy.
     * `lock_timeout` is how the block is observed rather than waited on.
     *
     * **WHAT THIS CASE DOES NOT PIN, because its mutant survived and saying so is the point.** Reverting
     * `findForMutation()` to `find()` leaves this GREEN. The reason is that the rival holds the row for the whole
     * handler run, so a handler that read without the lock simply blocks later — at `save()`'s UPDATE instead of at
     * the read — and rolls back all the same. § Gotchas 2026-07-31: *when a mutant survives a test written to kill
     * it, the test is not weak, it is not arriving.* The interleaving that distinguishes the two reads needs the rival
     * to COMMIT between the victim's read and the victim's write, which no single-threaded case can express while the
     * handler owns its own transaction; it would take a second PHP process, and that is what it would take rather than
     * something that cannot be done.
     *
     * **It is not needed for the hole, and that is the design answer rather than a consolation.** The hole is closed
     * STRUCTURALLY by `save()`'s write-once number predicate — a stale-read issue that reaches `save()` with a fresh
     * number is refused by the statement itself, whatever read produced it — and that is pinned by
     * `DoctrineInvoiceRepositoryTest::testANumberCommittedByARivalBetweenTheReadAndTheWriteIsNotOverwritten()`,
     * which drives a rival to commit between the victim's pre-read and its upsert through a DBAL `wrapperClass`.
     *
     * **This sentence named the WRONG test for five rounds** (round 6, correctness F1). It cited
     * `testADocumentNumberCannotBeRewrittenOnceAssigned()` and "three mutants", and deleting the predicate left
     * that test — and the whole 293-case integration suite — green: it never reaches the statement, because by the
     * time it attempts a rewrite the stored row already carries a number and `save()`'s pre-read sends it down the
     * state-only `UPDATE` branch. Its three mutants pin the PRE-READ branch. So the paragraph above leaned on a
     * structural closure that nothing held, which is the *"a control asserted in prose and enforced nowhere"*
     * shape — arriving through a citation rather than through an absence, and therefore invisible to a reader
     * who checked that the named test existed. The row lock is pinned in
     * both directions by `testLoadingForMutationBlocksAConcurrentWriterWhileAPlainReadDoesNot()`, and the handler's
     * use of it, in the right order relative to the allocation, by
     * `InvoiceWriteHandlersTest::testIssuingTakesTheDocumentForMutationBeforeItAllocatesANumber()`. What THIS case
     * adds is the composed outcome: a real handler, a real block, and no number spent.
     *
     * **The assertion is a SET DIFFERENCE, not a comparison with the document's number** — and that is the whole
     * lesson of the finding. Comparing the counter against the surviving row's number reports "no hole" for exactly
     * the lost-update case, because the overwriting number is the one that matches. Every number the counter has
     * handed out must be ON a document.
     */
    public function testAnIssueThatCannotHoldTheDocumentAllocatesNoNumber(): void
    {
        $created = self::creator()->handle(self::command());

        // A RIVAL TRANSACTION HOLDS THE ROW, on its own connection — a lock is a relationship between two sessions,
        // so a single connection could not express this and a test written that way would pass with no lock at all.
        $rival = self::rivalConnection();
        $rival->beginTransaction();
        $rival->executeQuery(
            'SELECT id FROM document WHERE company_id = :t AND id = :id FOR UPDATE',
            ['t' => self::TENANT, 'id' => $created->identity->id],
        );

        self::connection()->executeStatement("SET lock_timeout = '400ms'");

        try {
            self::issuer()->handle(new IssueInvoice($created->identity->id));
            self::fail(
                'The issue must not proceed while another transaction holds the document: acting on a stale draft is '
                . 'what allocates a number to a document somebody else is about to renumber.',
            );
        } catch (\Doctrine\DBAL\Exception $blocked) {
            self::assertStringContainsString('55P03', $blocked->getMessage(), $blocked->getMessage());
        } finally {
            self::connection()->executeStatement('SET lock_timeout = 0');
            $rival->rollBack();
            $rival->close();
        }

        // NOTHING WAS ALLOCATED, so the next successful issue is number 1. Read from the counter as well as from the
        // document, because the counter is where a burned number would be visible and the document is where it would
        // not.
        $issued = self::issuer()->handle(new IssueInvoice($created->identity->id));
        self::assertSame(1, $issued?->invoice->number()?->sequence(), 'the first issued invoice is number 1');

        $handedOut = (int) self::connection()->fetchOne(
            'SELECT next_value - 1 FROM document_number_sequence WHERE company_id = :t AND type = :type',
            ['t' => self::TENANT, 'type' => 'invoice'],
        );
        $onDocuments = array_map('intval', self::connection()->fetchFirstColumn(
            'SELECT number FROM document WHERE company_id = :t AND number IS NOT NULL',
            ['t' => self::TENANT],
        ));

        self::assertSame(
            [],
            array_values(array_diff(range(1, $handedOut), $onDocuments)),
            'every number the counter handed out must be ON a document — a set difference, because comparing the '
            . 'counter against the surviving row\'s number is exactly how a lost update reports "no hole"',
        );
    }

    /** A document that does not exist is `null` from the handler, which the transport turns into a 404. */
    public function testIssuingAnAbsentDocumentReturnsNull(): void
    {
        self::assertNull(self::issuer()->handle(new IssueInvoice('dddddddd-dddd-4ddd-8ddd-dddddddddddd')));
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * A DOCUMENT ADDRESSED TO A CLIENT THAT DOES NOT EXIST IS REFUSED — by the foreign key, in the domain's words.
     *
     * **THE TRANSLATION IS THE SUBJECT, not the refusal.** PostgreSQL was always going to reject this write; what
     * this case pins is that `DoctrineInvoiceRepository` turns DBAL's `ForeignKeyConstraintViolationException` into
     * `UnknownClient`, so a rule stated in `Domain/` is reported in the domain's vocabulary rather than the
     * driver's — and so the HTTP layer does not have to catch an infrastructure type to answer 422.
     *
     * The id is WELL FORMED, which is the whole point: a malformed one never reaches the database, because
     * `withClient()` refuses it first and `NewInvoiceInput` refuses it before that.
     *
     * **NOTHING IS LEFT BEHIND.** The handler owns the transaction, so the refusal rolls the whole thing back —
     * including the document number allocation, which is what makes a failed create leave no hole in the sequence.
     * That property has its own case; asserting the counter here as well would be a second copy of it.
     */
    public function testAnInvoiceAddressedToAClientThatDoesNotExistIsRefused(): void
    {
        $tnd = Currency::of('TND');
        $absent = '0199a5b2-0000-7000-8000-0000000000ff';

        $this->expectException(UnknownClient::class);
        $this->expectExceptionMessage($absent);

        self::creator()->handle(new CreateInvoice(
            $tnd,
            [new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19'))],
            [],
            $absent,
        ));
    }

    private static function command(): CreateInvoice
    {
        $tnd = Currency::of('TND');

        // TND on purpose: three decimal places, so a two-decimal assumption anywhere in the chain surfaces here rather
        // than in production. Two lines at one rate so the VAT allocation is not trivially the group total, and a
        // fixed charge of exactly 0.100 — Tunisia's stamp duty, which must represent exactly.
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

    private static function creator(): CreateInvoiceHandler
    {
        return new CreateInvoiceHandler(self::repository(), self::ids(), self::scope(), self::settings());
    }

    private static function issuer(): IssueInvoiceHandler
    {
        return new IssueInvoiceHandler(
            self::repository(),
            new DocumentNumberAllocator(new PostgresDocumentNumberSequence(self::connection(), self::context())),
            self::scope(),
            self::settings(),
        );
    }

    /**
     * The REAL settings adapter, on the same connection as everything else in this fixture.
     *
     * **THIS REPLACED A LITERAL `7` AND IS BEHAVIOUR-PRESERVING, which is the only reason it belongs in this
     * change.** The width used to be a constructor argument; it now comes from `company_settings`, and this test's
     * tenants have no row there, so the adapter answers with {@see CompanySettings::defaults()} — width 7 and
     * `PerRateGroup`, exactly what the two literals said. Every number this class asserts is unchanged.
     *
     * A double would have been easier and worse: this is the class that proves the lifecycle against a real
     * database, so the settings read it now performs should be a real query under the same row-level-security
     * policy as every other statement here. That the query returns nothing is the case, not an avoidance of one.
     */
    private static function settings(): DoctrineCompanySettingsRepository
    {
        return new DoctrineCompanySettingsRepository(self::connection(), self::context());
    }

    private static function scope(): DbalTransactionalScope
    {
        return new DbalTransactionalScope(self::connection());
    }

    /**
     * The REAL generator, not a fixed one.
     *
     * These cases never assert on an id's value, so predictability buys nothing — and using the real one means this is
     * also the only place the write path is exercised with genuinely v7-ordered ids, which is what the `document`
     * table's primary key sees in production.
     */
    private static function ids(): IdGenerator
    {
        return new UuidV7Generator(new \Twes\Infrastructure\Shared\SystemClock());
    }

    /**
     * A SECOND, INDEPENDENT connection that can hold a row lock — never memoised, and its caller closes it.
     *
     * Bound to the same tenant, session-scoped, for the reason `self::connection()` documents: the probe database is
     * fresh, so the runtime role has no grants here and the owner is used, and `document` is `FORCE ROW LEVEL
     * SECURITY` so even the owner needs the binding to see anything.
     */
    private static function rivalConnection(): Connection
    {
        try {
            $rival = DriverManager::getConnection([
                'driver' => 'pdo_pgsql',
                'host' => self::host(),
                'port' => (int) self::port(),
                'dbname' => self::DATABASE,
                'user' => self::ownerRole(),
                'password' => self::ownerPassword(),
            ]);
            $rival->executeStatement(
                \sprintf("SELECT set_config('%s', ?, false)", PostgresRowLevelSecurityIsolation::TENANT_SETTING),
                [self::TENANT],
            );

            return $rival;
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::fail('Could not open the rival probe connection: ' . $exception->getMessage());
        }
    }

    private static function repository(): DoctrineInvoiceRepository
    {
        return new DoctrineInvoiceRepository(self::connection(), self::context(), new InvoiceMapper());
    }

    private static function context(): InMemoryTenantContext
    {
        return InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT));
    }

    /** The serialised resource — what a client actually receives. */
    private static function payload(object $resource): string
    {
        return json_encode($resource, \JSON_THROW_ON_ERROR);
    }

    /**
     * The OWNER connection, bound to the tenant SESSION-wide.
     *
     * The owner because `document` is `FORCE ROW LEVEL SECURITY` and the probe database is created fresh, so the
     * runtime role's per-database grants (provisioned only for `twes_in_test`) do not exist here; tenant ISOLATION is
     * `BehaviouralIsolationTest`'s subject and is deliberately not re-proven in this class.
     *
     * Session-scoped (`set_config(..., false)`) rather than transaction-local, because these cases open and commit
     * several transactions on one connection. Production must use the transaction-local form — a session value leaks
     * to whoever gets the pooled connection next, which is what `PostgresRowLevelSecurityIsolation::bind()` exists to
     * prevent. Legitimate here only because this connection is not pooled and is discarded with the class.
     */
    private static function connection(): Connection
    {
        if (null === self::$connection) {
            try {
                self::$connection = DriverManager::getConnection([
                    'driver' => 'pdo_pgsql',
                    'host' => self::host(),
                    'port' => (int) self::port(),
                    'dbname' => self::DATABASE,
                    'user' => self::ownerRole(),
                    'password' => self::ownerPassword(),
                ]);
                self::$connection->executeStatement(
                    \sprintf("SELECT set_config('%s', ?, false)", PostgresRowLevelSecurityIsolation::TENANT_SETTING),
                    [self::TENANT],
                );
            } catch (\Doctrine\DBAL\Exception $exception) {
                self::fail('Could not connect to the probe database: ' . $exception->getMessage());
            }
        }

        return self::$connection;
    }
}
