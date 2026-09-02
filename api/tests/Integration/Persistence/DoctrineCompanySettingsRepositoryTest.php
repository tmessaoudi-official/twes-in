<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Settings\CompanySettings;
use Twes\Infrastructure\Persistence\Doctrine\DoctrineCompanySettingsRepository;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Tests\Integration\Tenancy\MigratedProbeDatabase;

/**
 * **`company_settings` WAS THE ONLY TENANT-OWNED TABLE WITH NO TWO-TENANT CASE (round 5, R5T-1), AND IT IS THE
 * ONE WHERE A MISS IS SILENT.**
 *
 * Every other adapter answers a cross-tenant lookup with `null` or a 404 — an absence a caller must handle, and
 * a test would notice. This one answers with `CompanySettings::defaults()`, deliberately: nothing creates a
 * settings row until Wave 7's tenant provisioning, so "no row" has to mean "has chosen nothing" rather than an
 * error. The adapter's own comment states the precondition that makes that safe:
 *
 *   *"Reaching this line already proves a tenant is bound and a transaction is open, which is what makes 'no
 *   row' mean 'has chosen nothing' rather than 'could not see'."*
 *
 * Nothing checked it. The failure it permits is the worst shape available here: a tenant with no row of its own
 * reading SOMEBODY ELSE'S — and the fields it would silently adopt are `default_vat_rounding_point`, which
 * decides **how much tax every document that tenant issues declares**, and the number-pattern width, which
 * decides what its invoice numbers look like. No exception, no 404, no log line: just a different, wrong,
 * legally-filed number.
 *
 * **TWO INDEPENDENT MECHANISMS DELIVER THE GUARANTEE, AND EACH IS PINNED ON ITS OWN — MEASURED 2026-09-02,
 * AFTER THIS DOCBLOCK SPENT A ROUND CLAIMING OTHERWISE.**
 *
 * | mutant | result |
 * |---|---|
 * | drop `FORCE ROW LEVEL SECURITY` (policy and `ENABLE` intact) | KILLED — `Failures: 1`, `testThePolicyAloneScopesTheTableWithNoHelpFromTheQuery` at the unscoped read |
 * | invert the adapter's predicate to `company_id <> :tenant` (`FORCE` intact) | KILLED — `Failures: 2`, `testATenantReadsBackItsOwnStoredSettings` and `testBReadingDoesNotDisturbAsSettings` |
 * | disable `requireTransaction()` | was UNPINNED until the two cases below were added |
 *
 * **The version of this docblock that shipped in `3aea5db` said the opposite of the first row** — that dropping
 * `FORCE` "leaves every assertion GREEN", that the surviving mutant was itself the finding, and that only a
 * COMBINED mutant pinned anything. None of that is true of this file, and the paragraph refuted itself two
 * sentences later by saying `testThePolicyAlone…` "covers the other half on its own". The likely history is
 * that the survival was measured against an earlier draft, BEFORE that case existed, and never re-run once the
 * case closed the gap — leaving a superseded measurement standing beside the test that falsifies it. That is
 * `CLAUDE.md` § Gotchas 2026-07-29 (*"a correction appended below a false statement is not a correction"*) in
 * its quietest form: the correction was adjacent, and the false sentence was simply never edited.
 *
 * Kept here rather than deleted because a recorded mutant result that does not reproduce is the artefact this
 * project most wants a reader to distrust — and this one had already been copied into `docs/SPEC.md` § 8 as
 * R5T-1's closure record before anything re-ran it. **Re-run a mutant after changing the file it was measured
 * against; adding a test can retire a survival.**
 *
 * An in-memory double cannot express any of this — it has no policy to obey — which is why
 * `InMemoryCompanySettingsRepository` and the functional tests over it, correct as they are, left this open.
 */
#[CoversClass(DoctrineCompanySettingsRepository::class)]
final class DoctrineCompanySettingsRepositoryTest extends TestCase
{
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_company_settings_probe';
    private const string TENANT_A = '0199a5b2-0000-7000-8000-0000000005aa';
    private const string TENANT_B = '0199a5b2-0000-7000-8000-0000000005bb';

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

    /**
     * EVERY CASE STARTS FROM A TABLE WITH NOTHING IN IT FOR THE TENANT IT IS ABOUT TO USE — which is a weaker
     * claim than "an empty table", and the difference is worth stating rather than glossing.
     *
     * `company_settings` is `FORCE ROW LEVEL SECURITY`, so this `DELETE` is policed like every other statement:
     * it removes what the CURRENTLY BOUND tenant can see, and what is bound here is whatever the previous case's
     * last `repositoryFor()` left. [Verified with a temporary probe: at the start of every case the bound tenant
     * sees zero rows.] Order-independence does not rest on the delete alone — `company_id` is the PRIMARY KEY, so
     * a tenant has at most one row and `save()` upserts over it.
     *
     * `CLAUDE.md` § Gotchas records two order-dependent cases that shared one row with no `setUp()`. The cheapest
     * way not to repeat that is to begin from a known state; the cheapest way to be WRONG about it is to describe
     * a stronger state than the statement actually produces.
     */
    protected function setUp(): void
    {
        self::connection()->executeStatement('DELETE FROM company_settings');
    }

    /**
     * **THE SILENT-MISS DIRECTION, which is the whole reason this file exists.**
     *
     * A has settings that differ from the defaults in BOTH fields, so neither can coincide with what B should
     * read. B has no row. B must get `defaults()` — never A's.
     *
     * Both fields are asserted rather than just the rounding point: a policy that leaked would leak the whole
     * row, and asserting one field would let the other regress unnoticed.
     */
    public function testATenantWithNoRowReadsTheDefaultsAndNeverAnotherTenantsSettings(): void
    {
        $stored = CompanySettings::of(
            NumberPattern::padded(4),
            VatRoundingPoint::PerLine,
        );

        // Guard the fixture itself: if A's settings ever equalled the defaults, this test would pass on a
        // completely broken policy. That is the fixture-cannot-express-the-shape trap, and it is cheap to close.
        self::assertNotSame(
            CompanySettings::defaults()->defaultVatRoundingPoint(),
            $stored->defaultVatRoundingPoint(),
            'the fixture must differ from the defaults, or a leak is indistinguishable from a miss',
        );
        self::assertNotSame(
            CompanySettings::defaults()->numberPattern()->width(),
            $stored->numberPattern()->width(),
        );

        $forA = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $forA->save($stored));

        $readByB = self::inTransaction(
            static fn(): CompanySettings => self::repositoryFor(self::TENANT_B)->forCurrentTenant(),
        );

        self::assertSame(
            CompanySettings::defaults()->defaultVatRoundingPoint(),
            $readByB->defaultVatRoundingPoint(),
            'B must read the DEFAULT rounding point -- reading A\'s would silently change how much tax B declares',
        );
        self::assertSame(
            CompanySettings::defaults()->numberPattern()->width(),
            $readByB->numberPattern()->width(),
            'B must read the DEFAULT number-pattern width, not A\'s',
        );
    }

    /**
     * The other half, and it is what stops the assertion above being satisfied by an adapter that simply always
     * returns the defaults: A reads back exactly what A stored.
     */
    public function testATenantReadsBackItsOwnStoredSettings(): void
    {
        $stored = CompanySettings::of(
            NumberPattern::padded(4),
            VatRoundingPoint::PerLine,
        );

        $forA = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $forA->save($stored));

        $restored = self::inTransaction(static fn(): CompanySettings => $forA->forCurrentTenant());

        self::assertSame(VatRoundingPoint::PerLine, $restored->defaultVatRoundingPoint());
        self::assertSame(4, $restored->numberPattern()->width());
    }

    /**
     * And A's row must still be A's after B has read: a read that fell through to `defaults()` must not have
     * written anything, and a leak in the other direction would show here.
     */
    public function testBReadingDoesNotDisturbAsSettings(): void
    {
        $forA = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $forA->save(
            CompanySettings::of(NumberPattern::padded(4), VatRoundingPoint::PerLine),
        ));

        self::inTransaction(static fn(): CompanySettings => self::repositoryFor(self::TENANT_B)->forCurrentTenant());

        $restored = self::inTransaction(
            static fn(): CompanySettings => self::repositoryFor(self::TENANT_A)->forCurrentTenant(),
        );

        self::assertSame(VatRoundingPoint::PerLine, $restored->defaultVatRoundingPoint());
        self::assertSame(4, $restored->numberPattern()->width());
    }

    /**
     * **THE POLICY HALF, ASKED DIRECTLY.**
     *
     * The adapter's query carries its own `WHERE company_id = :tenant`, so every assertion above would hold even
     * with row-level security switched off — proven by a surviving mutant, and recorded in this class's docblock.
     * This case removes that help: it reads `company_settings` with NO tenant predicate whatsoever, so the only
     * thing that can scope the result is the policy plus `FORCE`.
     *
     * It matters because the two mechanisms fail for different reasons. The predicate is application code and a
     * future query — a report, an export, an admin screen, a `migrations:diff` — may simply not carry it; the
     * policy is schema and covers every statement whatever issues it. `CLAUDE.md` is explicit that forgetting
     * must be IMPOSSIBLE rather than merely discouraged, and that is the half being asserted here.
     */
    public function testThePolicyAloneScopesTheTableWithNoHelpFromTheQuery(): void
    {
        $forA = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $forA->save(
            CompanySettings::of(NumberPattern::padded(4), VatRoundingPoint::PerLine),
        ));

        // Rebind to B, then read the WHOLE table. With the policy doing its job this is empty; without it, A's
        // row is right there, and the adapter's predicate is the only thing that would have hidden it.
        self::repositoryFor(self::TENANT_B);
        $rows = self::connection()->fetchAllAssociative('SELECT company_id FROM company_settings');

        self::assertSame([], $rows, 'bound to B, the policy alone must hide A\'s settings row');

        // ANTI-VACUITY: the same unscoped read as A must SEE the row, or an empty table would satisfy the
        // assertion above and this file would prove nothing at all.
        self::repositoryFor(self::TENANT_A);
        self::assertCount(
            1,
            self::connection()->fetchAllAssociative('SELECT company_id FROM company_settings'),
            'bound to A, the same unscoped read must see A\'s row -- otherwise the assertion above is vacuous',
        );
    }

    /**
     * **THE OTHER HALF OF THE PRECONDITION THE SILENT `defaults()` BRANCH RESTS ON.** This class's docblock says
     * that reaching that branch already proves a tenant is bound AND a transaction is open. The tenant half is
     * covered indirectly — `InvoiceWriteSurfaceTest` goes red if `NoTenantBound` is disabled, because
     * `CreateInvoiceHandler` reads settings first. The transaction half was covered by NOTHING until round 6:
     * disabling `requireTransaction()` left the whole 293-case integration suite at its baseline.
     *
     * That is the worst shape this adapter has, not the mildest. The binding is written by
     * `set_config(..., true)` and is transaction-LOCAL, so outside a transaction the connection is bound to no
     * tenant, the canonical policy's `nullif(...)` yields NULL, the table shows zero rows, and the caller is
     * handed `CompanySettings::defaults()` — the wrong rounding point and the wrong number width, on a document
     * that is then legally filed. No exception, no 404, no log line. Every production caller is inside a
     * transaction today (`CompanySettingsProvider`, `CompanySettingsProcessor`, both invoice handlers), so this
     * is not a live hole; it is a guard that was one refactor from being deleted with the suite green.
     *
     * Asserted on the MESSAGE rather than on the exception type, per `CLAUDE.md` § Gotchas 2026-07-29: a crash
     * and a detection are indistinguishable by exit status alone.
     *
     * **WHAT THESE TWO CASES PIN IS THE GUARD, NOT THE UNBOUND READ — and the distinction was measured rather
     * than assumed.** `repositoryFor()` binds with `set_config(..., false)`, i.e. for the SESSION, because the
     * probe needs the binding to outlive the individual transactions each helper opens. Production binds with
     * `true`, transaction-locally. So in this file the connection stays bound outside a transaction, and under
     * the `false &&` mutant both calls SUCCEED — `save()` writes and `forCurrentTenant()` returns the real row.
     * Neither throws [Verified 2026-09-02: `Failures: 2`, both *"Failed asserting that exception of type
     * RuntimeException is thrown"*]. The first draft of this docblock predicted a `WITH CHECK` policy violation
     * from `save()`; running it showed no exception at all. Recorded because a predicted mutant result is worth
     * nothing next to a measured one, and this file already carried one false measurement for a whole round.
     *
     * Same shape as `DoctrineProductRepositoryTest::testReadingOutsideATransactionIsRefused` and its `save()`
     * twin — four sibling adapters and the number sequence already carried this pair; `company_settings` was the
     * one that did not, and the one where the failure is silent rather than an absence a caller must handle.
     */
    public function testReadingOutsideATransactionIsRefused(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside a transaction/');

        $repository->forCurrentTenant();
    }

    public function testWritingOutsideATransactionIsRefused(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside a transaction/');

        $repository->save(CompanySettings::of(NumberPattern::padded(4), VatRoundingPoint::PerLine));
    }

    /**
     * Binds the connection the way the request path does — transaction-locally is what production uses, but the
     * probe needs the setting to outlive the individual transactions each helper opens, so `false` here.
     */
    private static function repositoryFor(string $tenant): DoctrineCompanySettingsRepository
    {
        self::connection()->executeStatement(
            \sprintf("SELECT set_config('%s', ?, false)", PostgresRowLevelSecurityIsolation::TENANT_SETTING),
            [$tenant],
        );

        return new DoctrineCompanySettingsRepository(
            self::connection(),
            InMemoryTenantContext::forTenant(TenantId::fromString($tenant)),
        );
    }

    private static function inTransaction(callable $work): mixed
    {
        $connection = self::connection();
        $connection->beginTransaction();

        try {
            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }
    }

    /**
     * The OWNER connection, exactly as the sibling repository suites use. `company_settings` is `FORCE ROW LEVEL
     * SECURITY` -- asserted for every tenant-owned table by `scripts/gates/schema-tenancy.php` -- so the owner is
     * policed too, and the binding above is what decides what this connection can see.
     *
     * This docblock used to end *"without `FORCE` this whole file would be vacuous"*. That is false, and it
     * contradicted the class docblock, which at the same commit claimed the opposite — that the FORCE mutant
     * survived. Measured: without `FORCE`, one case goes red
     * (`testThePolicyAloneScopesTheTableWithNoHelpFromTheQuery`) and the other three keep asserting real
     * behaviour through the adapter's own predicate. Neither sentence was right, and one file carrying two
     * mutually exclusive claims about one mutant is how both survived review.
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
                self::$connection->executeQuery('SELECT 1');
            } catch (\Doctrine\DBAL\Exception $exception) {
                self::fail('Could not connect to the probe database: ' . $exception->getMessage());
            }
        }

        return self::$connection;
    }
}
