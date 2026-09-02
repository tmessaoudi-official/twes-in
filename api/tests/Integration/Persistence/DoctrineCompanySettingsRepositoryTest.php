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
 * **TWO INDEPENDENT MECHANISMS DELIVER THE GUARANTEE, AND THE MUTANTS ARE HOW THAT WAS LEARNT RATHER THAN
 * ASSUMED.** The first version of this docblock said the file proved the row-level-security half. It does not:
 * dropping `FORCE` — which frees the owner connection these cases use from the policy — leaves every assertion
 * GREEN, because the adapter's own query also carries `WHERE company_id = :tenant`. That surviving mutant is the
 * finding, not a weakness: it is what defence in depth MEANS, and a docblock claiming otherwise would have
 * retired the question the way the sentence quoted above did.
 *
 * So the pin is the COMBINED mutant: invert the query's predicate AND drop `FORCE`, and
 * `testATenantWithNoRowReadsTheDefaultsAndNeverAnotherTenantsSettings` goes red naming the tax field.
 * `testThePolicyAloneScopesTheTableWithNoHelpFromTheQuery` covers the other half on its own, by reading the
 * table with NO tenant predicate at all — which is the only way to ask what the policy is doing here without
 * mutating production code.
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
     * EVERY CASE STARTS FROM AN EMPTY TABLE. `CLAUDE.md` § Gotchas records two order-dependent cases that shared
     * one row with no `setUp()`; the cheapest way not to repeat that is to begin from a known state.
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
     * policed too, and the binding above is what decides what this connection can see. Without `FORCE` this whole
     * file would be vacuous, which is the reason that gate exists.
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
