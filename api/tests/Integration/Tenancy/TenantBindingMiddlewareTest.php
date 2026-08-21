<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration\Tenancy;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Application\Document\CreateInvoice;
use Twes\Application\Document\CreateInvoiceHandler;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\DocumentState;
use Twes\Domain\Document\PersistedInvoice;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Infrastructure\Persistence\Doctrine\DbalTransactionalScope;
use Twes\Infrastructure\Persistence\Doctrine\DoctrineCompanySettingsRepository;
use Twes\Infrastructure\Persistence\Doctrine\DoctrineInvoiceRepository;
use Twes\Infrastructure\Persistence\Doctrine\InvoiceMapper;
use Twes\Infrastructure\Shared\SystemClock;
use Twes\Infrastructure\Shared\UuidV7Generator;
use Twes\Infrastructure\Tenancy\Doctrine\TenantBindingConnection;
use Twes\Infrastructure\Tenancy\Doctrine\TenantBindingDriver;
use Twes\Infrastructure\Tenancy\Doctrine\TenantBindingMiddleware;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Tests\Integration\DatabaseRequirement;

/**
 * THE WRITE AND READ PATHS AS THE RUNTIME ROLE, WITH NOTHING BUT THE MIDDLEWARE BINDING THE TENANT.
 *
 * **This class exists because a defect hid behind every other fixture in this suite.** `bind()` was written in Wave 0,
 * documented everywhere as the primary tenancy control, and had **no production call site**: `RequestTenantBinder` set
 * the in-memory context and nothing wrote `twes.tenant_id` on the connection. `POST /api/invoices` answered
 * `SQLSTATE[42501] new row violates row-level security policy` and a tenant asking for its OWN document got a 404.
 *
 * It stayed invisible for three commits because the two fixtures that exercise this code — `InvoiceLifecycleTest` and
 * `DoctrineInvoiceRepositoryTest` — each connect as the table OWNER and set the GUC **session-wide themselves**. Both
 * are defensible on their own terms and both are documented as such, and together they made the whole tenancy
 * lifecycle unobservable: they supply, by hand, exactly the two things production was missing. So this class is
 * deliberately the inverse on both axes — **the restricted runtime role, and not one line that touches the setting.**
 *
 * Everything here consequently fails without the middleware, which is what makes it the mutant harness for it:
 * removing `TenantBindingMiddleware` from `middlewares()` turns the write into a 42501 and every read into a null.
 * That is asserted rather than described — see {@see testWithoutTheMiddlewareTheSameWriteIsRefusedByRowSecurity()}.
 *
 * A HAND-BUILT DBAL connection rather than a booted kernel, for the reason `SavepointGuardMiddlewareTest` gives: the
 * kernel proves the container wiring and not the behaviour. `lint:container` plus the `debug:container --tag` scoping
 * check cover the registration; this covers what the middleware does.
 *
 * The `!$native instanceof \PDO` branch is NOT covered here and is not claimed to be: reaching it needs a driver whose
 * native connection is not a `\PDO`, and `pdo_pgsql` is the only driver this project supports. It is a refusal rather
 * than a silent no-op precisely so that the day somebody switches driver, the failure names itself.
 */
#[CoversClass(TenantBindingMiddleware::class)]
#[CoversClass(TenantBindingDriver::class)]
#[CoversClass(TenantBindingConnection::class)]
final class TenantBindingMiddlewareTest extends TestCase
{
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_tenant_binding_probe';
    private const TENANT_A = '0199a5b2-0000-7000-8000-00000000020a';
    private const TENANT_B = '0199a5b2-0000-7000-8000-00000000020b';

    /** @var list<Connection> */
    private array $open = [];

    public static function setUpBeforeClass(): void
    {
        self::createMigratedProbeDatabase(self::DATABASE);
        self::grantRuntimeDml();
    }

    public static function tearDownAfterClass(): void
    {
        self::dropProbeDatabase(self::DATABASE);
    }

    protected function setUp(): void
    {
        // A CLEAN SLATE PER CASE, deleted as the SUPERUSER rather than through a bound connection. Two cases count
        // rows, so a leftover document would make them depend on execution order — and doing the cleanup through a
        // bound connection would mean this fixture exercised the very control the cases are asking about, so a
        // regression in binding would corrupt the setup instead of failing the assertion.
        $admin = self::connectionTo(self::DATABASE, self::superuserName(), self::superuserPassword());

        foreach (['document_line', 'document_charge', 'document', 'document_number_sequence'] as $table) {
            $admin->exec(\sprintf('DELETE FROM %s', $table));
        }
    }

    protected function tearDown(): void
    {
        // EVERY CONNECTION THIS CASE OPENED IS CLOSED, and it matters more here than in most fixtures: each one holds
        // a server-side session, several cases deliberately leave a failed transaction behind, and `DROP DATABASE`
        // in `tearDownAfterClass()` would otherwise need `WITH (FORCE)` to evict them.
        foreach ($this->open as $connection) {
            try {
                $connection->close();
            } catch (\Throwable) {
                // Discarded either way; a close failure says nothing this test is about.
            }
        }

        $this->open = [];
    }

    /**
     * THE DEFECT, INVERTED: a create as the runtime role, with the middleware as the only thing that binds.
     *
     * Nothing in this case mentions `twes.tenant_id`, `set_config`, or the owner role. The handler opens a
     * transaction through `DbalTransactionalScope`, DBAL calls the driver's `beginTransaction()` exactly once, and
     * `TenantBindingConnection` writes the setting there. If it does not, the INSERT into `document` violates the
     * canonical policy's `WITH CHECK` half and PostgreSQL refuses with 42501.
     */
    public function testTheWritePathSucceedsAsTheRuntimeRoleWithNothingButTheMiddlewareBinding(): void
    {
        $connection = $this->connection(self::TENANT_A);
        $created = $this->creatorOn($connection, self::TENANT_A)->handle(self::command());

        self::assertNotSame('', $created->identity->id, 'the document was written and read back');
        self::assertSame(DocumentState::Draft, $created->invoice->state());

        // AND THE ROW IS REALLY THERE, counted through a bound transaction rather than inferred from the handler
        // returning. `CreateInvoiceHandler` re-reads inside its own transaction, so a handler that returned the
        // aggregate it had just built would satisfy every assertion above without a row existing at all.
        $rows = new DbalTransactionalScope($connection)
            ->transactional(static fn(): int => (int) $connection->fetchOne('SELECT count(*) FROM document'));

        self::assertSame(1, $rows, 'exactly one document, visible to the tenant that wrote it');
    }

    /**
     * THE MUTANT, ASSERTED IN THE SUITE RATHER THAN LEFT TO A COMMIT MESSAGE.
     *
     * `CLAUDE.md` § Gotchas: *a fix is not delivered until a MUTANT proves it load-bearing.* The usual way to satisfy
     * that is to delete the fix by hand and watch the suite go red, which leaves no artefact — so here the mutant is a
     * case. It builds the identical handler on a connection with an EMPTY middleware list and asserts the exact
     * failure the production defect produced, which also pins the diagnosis: 42501 on `document`, not a permission
     * error and not a silent success.
     *
     * This is the one case that would pass at the defective commit, and that is deliberate: it is what makes the
     * other four meaningful.
     */
    public function testWithoutTheMiddlewareTheSameWriteIsRefusedByRowSecurity(): void
    {
        $unbound = $this->connection(self::TENANT_A, bind: false);

        try {
            $this->creatorOn($unbound, self::TENANT_A)->handle(self::command());
            self::fail('An unbound INSERT must be refused: row-level security is what makes forgetting to bind safe.');
        } catch (\Doctrine\DBAL\Exception $refused) {
            self::assertStringContainsString(
                'violates row-level security policy',
                $refused->getMessage(),
                'the refusal must come from the POLICY, not from a missing grant — a permission error here would mean '
                . 'the fixture, rather than the tenancy control, is what refused',
            );
        }
    }

    /**
     * A FETCH IN A LATER TRANSACTION SEES WHAT THE FIRST ONE COMMITTED.
     *
     * Separate from the create case because it proves the binding is made per TRANSACTION rather than once per
     * connection. The setting is written with `set_config(..., true)` — transaction-local — so it is gone the moment
     * the create commits, and the read needs its own. A middleware that bound on `connect()` instead would pass the
     * case above and fail this one.
     */
    public function testAFetchInALaterTransactionSeesTheCommittedDocument(): void
    {
        $connection = $this->connection(self::TENANT_A);
        $created = $this->creatorOn($connection, self::TENANT_A)->handle(self::command());
        $repository = $this->repositoryOn($connection, self::TENANT_A);
        $scope = new DbalTransactionalScope($connection);

        $found = $scope->transactional(fn(): ?PersistedInvoice => $repository->find($created->identity->id));

        self::assertNotNull($found, 'the tenant that wrote the document can read it back in a new transaction');
        self::assertSame($created->identity->id, $found->identity->id);
    }

    /**
     * A READ OUTSIDE A TRANSACTION IS UNBOUND, AND THEREFORE SEES NOTHING.
     *
     * **This is why `InvoiceProvider` wraps its lookup in a transaction**, which reads like ceremony and is not. The
     * binding is transaction-local by design — a session-scoped one survives into whoever gets the pooled connection
     * next, which is a cross-tenant read on the most innocent possible path — so a query issued with no transaction
     * open is issued with `twes.tenant_id` unset, the canonical policy compares against NULL, and the tenant's own
     * document is invisible to it.
     *
     * Asserted here rather than in `InvoiceProvider`'s own test because the property belongs to the database, and
     * because this is the case that goes red if somebody removes that wrapper as redundant.
     */
    public function testTheSameFetchOutsideATransactionSeesNothing(): void
    {
        $connection = $this->connection(self::TENANT_A);
        $created = $this->creatorOn($connection, self::TENANT_A)->handle(self::command());

        // No `transactional()`. One statement, its own implicit transaction, no `beginTransaction()` for the
        // middleware to hook — so nothing bound it.
        $found = $this->repositoryOn($connection, self::TENANT_A)->find($created->identity->id);

        self::assertNull(
            $found,
            'an unbound read must see nothing: that fail-closed direction is the whole design of the policy, and it '
            . 'is what makes the transaction in InvoiceProvider load-bearing rather than decorative',
        );
    }

    /**
     * ANOTHER TENANT'S CONNECTION, CORRECTLY BOUND TO ITSELF, STILL SEES NOTHING.
     *
     * The cross-tenant direction, and note what it is NOT: it is not a re-proof of row-level security, which is
     * `BehaviouralIsolationTest`'s subject and is attacked there far harder. It is a proof that the middleware binds
     * the tenant it was given — a wrapper that bound a constant, or the first tenant it ever saw, or nothing at all
     * would pass every case above and fail only this one.
     */
    public function testATenantBoundToItselfCannotSeeAnotherTenantsDocument(): void
    {
        $a = $this->connection(self::TENANT_A);
        $created = $this->creatorOn($a, self::TENANT_A)->handle(self::command());

        $b = $this->connection(self::TENANT_B);
        $repository = $this->repositoryOn($b, self::TENANT_B);
        $found = new DbalTransactionalScope($b)
            ->transactional(fn(): ?PersistedInvoice => $repository->find($created->identity->id));

        self::assertNull($found, 'tenant B is bound to B, so A\'s document is not in its view');

        // AND B'S OWN WRITE STILL WORKS on the same connection, which is what separates "bound to B" from "bound to
        // nothing". Without it the assertion above is satisfied by a middleware that binds an empty string.
        $ownDocument = $this->creatorOn($b, self::TENANT_B)->handle(self::command());
        self::assertNotSame(
            $created->identity->id,
            $ownDocument->identity->id,
            'B wrote its own document, so its connection is bound to B rather than merely unbound',
        );
    }

    /**
     * A TENANT-LESS TRANSACTION IS PERMITTED, OPENS, AND SEES NOTHING.
     *
     * `TenantBindingConnection` returns early when the context holds no tenant, because `TenantContext` documents
     * genuinely tenant-less work — installation, a global health check, a cross-tenant migration — and refusing would
     * break it. That early return is the one place this class could hide a hole, so the safety it relies on is
     * asserted directly: the transaction opens without throwing, and a raw count over a table that DOES hold rows
     * returns zero.
     */
    public function testATenantLessTransactionOpensAndSeesNothing(): void
    {
        $this->creatorFor(self::TENANT_A)->handle(self::command());

        $unbound = $this->connection(null);
        $rows = new DbalTransactionalScope($unbound)
            ->transactional(static fn(): int => (int) $unbound->fetchOne('SELECT count(*) FROM document'));

        self::assertSame(
            0,
            $rows,
            'a document exists, and an unbound transaction sees none of it — the early return is safe because the '
            . 'policy refuses, not because this class checked',
        );
    }

    /**
     * Give the runtime role the DML it has in production, derived from the catalogue rather than listed.
     *
     * The migration issues no `GRANT`, and the privileges the runtime role really runs with come from
     * `ALTER DEFAULT PRIVILEGES ... IN SCHEMA public` in `scripts/dev/provision-test-database.sh` — **per-database**
     * catalogue entries, so they exist for `twes_in_test` and for nothing else. `BehaviouralIsolationTest` records the
     * same discovery; the note is repeated because a fresh probe database otherwise refuses every statement with
     * `permission denied for table document`, which reads like a tenancy failure and is not one.
     *
     * Derived from `pg_class` rather than from a written list of the four document tables, so a tenant table added
     * later is granted automatically instead of failing in whichever case touches it first. TRUNCATE is excluded, as
     * the provisioning script excludes it: it is never subject to row security, so granting it would hand this
     * fixture the one privilege production withholds.
     */
    private static function grantRuntimeDml(): void
    {
        $admin = self::connectionTo(self::DATABASE, self::superuserName(), self::superuserPassword());
        $tables = $admin->query(
            'SELECT quote_ident(c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace'
            . " WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')",
        );

        self::assertNotFalse($tables, 'could not list the probe database\'s tables');
        $names = $tables->fetchAll(\PDO::FETCH_COLUMN);

        self::assertNotEmpty($names, 'the probe database has no tables, so the migration did not reach it');

        foreach ($names as $table) {
            $admin->exec(\sprintf(
                'GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO %s',
                $table,
                '"' . str_replace('"', '""', self::runtimeRole()) . '"',
            ));
        }
    }

    /**
     * The command every case writes — one line, one rate, in TND.
     *
     * TND because it has three decimal places, so a two-decimal assumption anywhere in the chain surfaces here. Kept
     * simpler than `InvoiceLifecycleTest`'s: the arithmetic is that class's subject, and here the document is only a
     * vehicle for asking whether the row was visible.
     */
    private static function command(): CreateInvoice
    {
        $tnd = Currency::of('TND');

        return new CreateInvoice(
            $tnd,
            [new DocumentLine('2', Money::of('1.936', $tnd), Rate::fromPercentage('19'))],
            [],
        );
    }

    private function creatorFor(string $tenant): CreateInvoiceHandler
    {
        return $this->creatorOn($this->connection($tenant), $tenant);
    }

    private function creatorOn(Connection $connection, string $tenant): CreateInvoiceHandler
    {
        return new CreateInvoiceHandler(
            $this->repositoryOn($connection, $tenant),
            new UuidV7Generator(new SystemClock()),
            new DbalTransactionalScope($connection),
            // THE REAL ADAPTER, not a double, and on the SAME connection — which is the whole point in this class.
            // Creating a document now reads `company_settings` for the tenant's rounding point, so that read is one
            // more statement the middleware's binding has to cover. A double would make this fixture blind to the
            // very thing the file exists to prove. With no settings row the adapter answers with the documented
            // defaults, so behaviour on the bound path is exactly what it was before the settings table landed.
            new DoctrineCompanySettingsRepository($connection, self::contextFor($tenant)),
        );
    }

    private function repositoryOn(Connection $connection, string $tenant): DoctrineInvoiceRepository
    {
        return new DoctrineInvoiceRepository($connection, self::contextFor($tenant), new InvoiceMapper());
    }

    private static function contextFor(?string $tenant): InMemoryTenantContext
    {
        return null === $tenant
            ? InMemoryTenantContext::empty()
            : InMemoryTenantContext::forTenant(TenantId::fromString($tenant));
    }

    /**
     * A DBAL connection to the probe database AS THE RUNTIME ROLE, with the binding middleware installed.
     *
     * `$bind: false` is the mutant switch: the identical connection with an empty middleware list, which is exactly
     * the production wiring at the defective commit. It is a parameter rather than a second method so that the two
     * cannot drift in any other respect — a mutant that also changed the role or the database would prove nothing.
     *
     * A FRESH CONNECTION PER CALL, never memoised. Two cases need two tenants at once, and a shared connection would
     * make "B cannot see A's document" depend on which context happened to be attached last.
     */
    private function connection(?string $tenant, bool $bind = true): Connection
    {
        $configuration = new Configuration();

        if ($bind) {
            $configuration->setMiddlewares([
                new TenantBindingMiddleware(new PostgresRowLevelSecurityIsolation(), self::contextFor($tenant)),
            ]);
        }

        try {
            $connection = DriverManager::getConnection(
                [
                    'driver' => 'pdo_pgsql',
                    'host' => self::host(),
                    'port' => (int) self::port(),
                    'dbname' => self::DATABASE,
                    'user' => self::runtimeRole(),
                    'password' => self::runtimePassword(),
                ],
                $configuration,
            );

            // CONNECT EAGERLY, for the reason `SavepointGuardMiddlewareTest` gives: `getConnection()` is lazy, so an
            // unreachable cluster would otherwise surface inside whichever assertion queried first instead of as
            // `DatabaseRequirement`'s message, which names the two-cluster trap § Gotchas 2026-07-30 records.
            $connection->executeQuery('SELECT 1');
            $this->open[] = $connection;

            return $connection;
        } catch (\Doctrine\DBAL\Exception $exception) {
            $driverFailure = $exception->getPrevious();

            while (null !== $driverFailure && !$driverFailure instanceof \PDOException) {
                $driverFailure = $driverFailure->getPrevious();
            }

            self::fail(
                $driverFailure instanceof \PDOException
                    ? DatabaseRequirement::unreachable($driverFailure)
                    : 'Could not open a bound DBAL connection: ' . $exception->getMessage(),
            );
        }
    }
}
