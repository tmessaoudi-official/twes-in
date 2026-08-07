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
use Twes\Domain\Document\DocumentNumberSequence;
use Twes\Domain\Document\DocumentType;
use Twes\Infrastructure\Persistence\Doctrine\PostgresDocumentNumberSequence;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Tests\Integration\Tenancy\MigratedProbeDatabase;
use Twes\Tests\Unit\Document\DocumentNumberSequenceContract;

/**
 * THE POSTGRES COUNTER, AGAINST A REAL ROW LOCK. The obligation `DocumentNumberSequenceContract` disclosed.
 *
 * That class asserts four of the port's five guarantees and states in its own docblock that the fifth — **Serialised**
 * — is *"owed by the adapter's own test"*, naming exactly what it would take: two connections allocating for one
 * `(tenant, type)` inside overlapping transactions, asserting the second BLOCKS until the first commits and then
 * returns the next value rather than the same one. {@see self::testConcurrentAllocationsForOneTenantSerialise()} is
 * that, and it is the reason this class is in the `integration` suite rather than beside the in-memory double: a
 * single-process fake has no concurrency to violate.
 *
 * **THE FIXTURE OPENS A TRANSACTION, and that is production's shape rather than a workaround.** The contract's cases
 * call `allocateNext()` directly, and the adapter refuses outside a transaction, because an autocommitted increment
 * would not roll back with the document — `nextval()`'s defect reintroduced by hand. In production the transaction
 * comes from `IssueInvoiceHandler`'s `TransactionalScope`; here it comes from {@see self::sequence()}.
 *
 * **A FRESH TENANT PER `sequence()` CALL** is what satisfies the contract's requirement of "a fresh, empty one on
 * every call" without truncating anything: the counter is keyed by `(company_id, type)`, so an unused tenant id has
 * no counter row for any type. Cheaper than a `TRUNCATE`, and it also means every case is implicitly a test that
 * counters do not leak between tenants.
 */
#[CoversClass(PostgresDocumentNumberSequence::class)]
final class PostgresDocumentNumberSequenceTest extends DocumentNumberSequenceContract
{
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_number_sequence_probe';

    private static ?Connection $connection = null;

    /** Incremented per tenant so ids are distinct without needing randomness, which this project forbids in scripts. */
    private static int $tenantCounter = 0;

    public static function setUpBeforeClass(): void
    {
        self::createMigratedProbeDatabase(self::DATABASE);
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection = null;
        self::dropProbeDatabase(self::DATABASE);
    }

    protected function tearDown(): void
    {
        // ROLLED BACK, not committed. Every case's allocations are discarded, which keeps the cases independent and —
        // more usefully — means the whole suite runs inside the very mechanism it is asserting.
        $connection = self::connection();

        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    /**
     * **THE GUARANTEE THE CONTRACT CANNOT ASSERT: concurrent issues for one `(tenant, type)` SERIALISE.**
     *
     * Its violation is *two invoices sharing a number*, which `build-waves.plan.md` calls a worse outcome than a
     * queued request — so this is the case that justifies accepting the throughput cost.
     *
     * **HOW BLOCKING IS PROVEN WITHOUT A TIMING ASSERTION.** A test that measures elapsed time to show "it waited"
     * is flaky by construction. Instead the second session sets `lock_timeout` and PostgreSQL reports the block as a
     * specific error: `55P03`, `lock_not_available`. So the block becomes a deterministic, named observation rather
     * than a duration. Then the first session commits, the second retries, and the assertion that matters is that it
     * gets **3** — it saw the committed increment rather than re-reading the value the first session had taken.
     *
     * **THIS CASE IS WHY THE ADAPTER IS ONE STATEMENT, and the history is worth keeping because the test came first
     * and was wrong twice.** Against the earlier ensure/lock/increment implementation, deleting ` FOR UPDATE` left the
     * whole suite green — twice, the second time after this fixture was corrected to commit the counter row first
     * [Verified: `OK (19 tests, 35 assertions)` against that mutant]. Measuring it showed
     * `INSERT … ON CONFLICT DO NOTHING` blocking on its own (`canceling statement due to lock timeout … while
     * inserting index tuple`), so under contention the lock was never the thing serialising the two sessions and no
     * single-process test could observe it. § Gotchas 2026-07-31, exactly: *when a mutant survives a test written to
     * kill it, the test is not weak, it is not arriving.* The conclusion was to change the code rather than to build a
     * statement-interleaving harness for a lock that only closed a window between two statements of the same session.
     *
     * So what this case pins now is the property rather than a token: two overlapping transactions cannot both take
     * the same number, and the loser gets the next one. It is killed by both of the mutants the adapter's own
     * statement admits — `EXCLUDED.next_value` in place of the table-qualified column, and dropping the `- 1` from the
     * `RETURNING` clause — each of which turns this suite red in 11 or more cases.
     *
     * **THE COMMITTED SEED IS KEPT even though the corrected diagnosis no longer needs it**, because it makes this the
     * harder of the two contention shapes: an existing counter, where the conflict path rather than the insert path is
     * what has to serialise. The brand-new-key race is covered too, by every other case in the class.
     */
    public function testConcurrentAllocationsForOneTenantSerialise(): void
    {
        $tenant = self::freshTenant();

        // SEED AND COMMIT. See the docblock: without this the contention lands on the INSERT and the row lock this
        // case exists to test is never exercised at all.
        $seed = self::connectionFor($tenant);
        $seed->beginTransaction();
        self::assertSame(1, self::sequenceOn($seed, $tenant)->allocateNext(DocumentType::Invoice));
        $seed->commit();
        $seed->close();

        $first = self::connectionFor($tenant);
        $second = self::connectionFor($tenant);

        try {
            $first->beginTransaction();
            self::assertSame(
                2,
                self::sequenceOn($first, $tenant)->allocateNext(DocumentType::Invoice),
                'the first session takes number 2 and holds the row lock',
            );

            $second->beginTransaction();
            // TRANSACTION-LOCAL, so it cannot leak to the retry below and turn a genuine block into a pass.
            $second->executeStatement("SET LOCAL lock_timeout = '250ms'");

            try {
                $racing = self::sequenceOn($second, $tenant)->allocateNext(DocumentType::Invoice);
                self::fail(\sprintf(
                    'The second session allocated %d while the first still held the row lock and had taken 2. '
                    . 'Without serialisation both documents take the same number — two invoices sharing a legal '
                    . 'document number, which is the outcome the port\'s fifth guarantee exists to prevent.',
                    $racing,
                ));
            } catch (\Doctrine\DBAL\Exception\DriverException $blocked) {
                self::assertSame(
                    '55P03',
                    $blocked->getSQLState(),
                    'the block must be PostgreSQL\'s lock_not_available, not some other failure: ' . $blocked->getMessage(),
                );
            }

            $second->rollBack();
            $first->commit();

            // AND NOW IT SEES THE COMMITTED VALUE. This half is what distinguishes "it blocked" from "it blocked and
            // then still read a stale value", which is the failure a lock without a re-read would produce.
            $second->beginTransaction();
            self::assertSame(
                3,
                self::sequenceOn($second, $tenant)->allocateNext(DocumentType::Invoice),
                'once the first transaction commits, the second must take the NEXT number and not the same one',
            );
            $second->rollBack();
        } finally {
            foreach ([$first, $second] as $connection) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                $connection->close();
            }
        }
    }

    /**
     * **A ROLLED-BACK ALLOCATION RETURNS THE NUMBER. This is the whole reason a `SEQUENCE` is forbidden.**
     *
     * `nextval()` is deliberately non-transactional, so a failed issue burns its number and leaves a permanent hole —
     * what a tax authority reads as a suppressed sale, and what France and Tunisia audit for. A counter ROW inside the
     * caller's transaction does not, and this is the assertion that proves the difference rather than restating it.
     *
     * The contract's gaplessness case cannot see this: it allocates repeatedly inside one transaction, where a
     * sequence would look gapless too.
     */
    public function testARolledBackAllocationReturnsTheNumberRatherThanBurningIt(): void
    {
        $tenant = self::freshTenant();
        $connection = self::connectionFor($tenant);

        try {
            $connection->beginTransaction();
            self::assertSame(1, self::sequenceOn($connection, $tenant)->allocateNext(DocumentType::Invoice));
            $connection->rollBack();

            $connection->beginTransaction();
            self::assertSame(
                1,
                self::sequenceOn($connection, $tenant)->allocateNext(DocumentType::Invoice),
                'after a rollback the number must be AVAILABLE AGAIN — a hole in an invoice sequence is what a tax '
                . 'authority reads as a suppressed sale, which is why nextval() is forbidden here',
            );
            $connection->rollBack();
        } finally {
            $connection->close();
        }
    }

    /** Counters are per TENANT as well as per type: a second tenant starts at 1 while the first is at 4. */
    public function testCountersAreIndependentPerTenant(): void
    {
        $first = self::sequence();

        for ($i = 0; $i < 3; ++$i) {
            $first->allocateNext(DocumentType::Invoice);
        }

        self::assertSame(4, $first->allocateNext(DocumentType::Invoice), 'the first tenant is at 4');
        self::assertSame(
            1,
            self::sequence()->allocateNext(DocumentType::Invoice),
            'a different tenant must start at 1 — a shared counter would leak one tenant\'s document volume to '
            . 'another, and would let either deny the other its numbering',
        );
    }

    /**
     * ALLOCATING OUTSIDE A TRANSACTION IS REFUSED, for the two reasons the adapter's docblock gives.
     *
     * Not a nicety: without the enclosing transaction the `FOR UPDATE` lock is released before the `UPDATE` lands, so
     * two concurrent sessions read the same value, and the increment would not roll back with the document.
     */
    public function testAllocatingOutsideATransactionIsRefused(): void
    {
        $tenant = self::freshTenant();
        $connection = self::connectionFor($tenant);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('outside a transaction');

            self::sequenceOn($connection, $tenant)->allocateNext(DocumentType::Invoice);
        } finally {
            $connection->close();
        }
    }

    /** And with no tenant bound, because a tenant-less allocation has no counter to advance. */
    public function testAllocatingWithNoTenantBoundIsRefused(): void
    {
        $connection = self::connection();
        $connection->beginTransaction();

        $sequence = new PostgresDocumentNumberSequence($connection, InMemoryTenantContext::empty());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no tenant bound');

        $sequence->allocateNext(DocumentType::Invoice);
    }

    /**
     * A FRESH, EMPTY ADAPTER — the contract's requirement, met with a fresh TENANT rather than by truncating.
     *
     * The transaction is opened here because the adapter refuses without one and the contract's cases call
     * `allocateNext()` directly. `tearDown()` rolls it back.
     */
    protected function sequence(): DocumentNumberSequence
    {
        $connection = self::connection();
        $tenant = self::freshTenant();

        if (!$connection->isTransactionActive()) {
            $connection->beginTransaction();
        }

        return self::sequenceOn($connection, $tenant);
    }

    /** A tenant id nothing has used yet, so it has no counter row for any type. */
    private static function freshTenant(): string
    {
        // Derived from a counter rather than `random_bytes`, so a failure is reproducible. `7` in the version
        // position and `8` in the variant position because `TenantId::fromString()` validates the canonical form.
        return \sprintf('0199a5b2-0000-7000-8000-%012d', ++self::$tenantCounter);
    }

    /**
     * The adapter, with the database session bound to `$tenant` and the application context agreeing.
     *
     * **BOTH HALVES MATTER AND THEY ARE SET SEPARATELY, which is deliberate rather than redundant.** `set_config`
     * binds the row-level-security policy; `InMemoryTenantContext` is what the adapter reads to build its `WHERE`
     * clause. Production keeps them in step through `RequestTenantBinder` and the connection middleware; a test that
     * set only one would silently exercise the divergence the adapter's read-back guard exists to report.
     *
     * SESSION-scoped (`set_config(..., false)`) rather than transaction-local, because these cases begin and roll back
     * several transactions on one connection and a transaction-local binding would vanish with the first rollback.
     * Production must use the transaction-local form — a session value leaks to whoever gets the pooled connection
     * next, which is what `PostgresRowLevelSecurityIsolation::bind()` exists to avoid. Legitimate here only because
     * these connections are not pooled and are discarded with the class.
     */
    private static function sequenceOn(Connection $connection, string $tenant): PostgresDocumentNumberSequence
    {
        $connection->executeStatement(
            \sprintf("SELECT set_config('%s', ?, false)", PostgresRowLevelSecurityIsolation::TENANT_SETTING),
            [$tenant],
        );

        return new PostgresDocumentNumberSequence(
            $connection,
            InMemoryTenantContext::forTenant(TenantId::fromString($tenant)),
        );
    }

    /** A NEW connection, for the concurrency case: two sessions cannot share one. */
    private static function connectionFor(string $tenant): Connection
    {
        $connection = self::openConnection();
        $connection->executeStatement(
            \sprintf("SELECT set_config('%s', ?, false)", PostgresRowLevelSecurityIsolation::TENANT_SETTING),
            [$tenant],
        );

        return $connection;
    }

    /**
     * The shared connection for the contract's cases.
     *
     * The OWNER role, for the reason `DoctrineInvoiceRepositoryTest` gives: `document_number_sequence` is
     * `FORCE ROW LEVEL SECURITY`, so the owner is policed too and the binding above is what makes anything visible.
     * The probe database is created fresh and the runtime role's per-database grants are provisioned only for
     * `twes_in_test`; tenant ISOLATION is `BehaviouralIsolationTest`'s subject and is deliberately not re-proven here.
     */
    private static function connection(): Connection
    {
        return self::$connection ??= self::openConnection();
    }

    private static function openConnection(): Connection
    {
        try {
            $connection = DriverManager::getConnection([
                'driver' => 'pdo_pgsql',
                'host' => self::host(),
                'port' => (int) self::port(),
                'dbname' => self::DATABASE,
                'user' => self::ownerRole(),
                'password' => self::ownerPassword(),
            ]);
            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::fail('Could not connect to the probe database: ' . $exception->getMessage());
        }
    }
}
