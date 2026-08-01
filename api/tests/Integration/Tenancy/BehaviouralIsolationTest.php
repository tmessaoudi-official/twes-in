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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;

/**
 * TENANT ISOLATION, PROVEN BY ATTACK: two tenants with real rows, and every relation discovery finds is attacked
 * as the restricted runtime role.
 *
 * **WHY THIS EXISTS, and why it replaces most of `schema-tenancy.php` rather than supplementing it** (developer
 * ruling, 2026-08-01, recorded in `build-waves.plan.md`). Certification round 22 produced SIX P0s in that gate
 * while the schema it guards survived every attack both security lenses could build — every confirmed breach was
 * in the checker, none in the thing checked. The unifying diagnosis: each P0 came from **inferring a property from
 * a description** (catalogue metadata, or source text) instead of **observing the thing itself**. `indkey` vs
 * `indnkeyatts`; `contype` missing `'x'`; view-owner semantics; `pg_roles`' own row vs membership;
 * `text::regclass` vs the oid already in hand.
 *
 * Enumerating implementation SHAPES is unbounded, and PostgreSQL keeps adding to it. Enumerating attacker GOALS —
 * read, write, re-parent, delete, erase, probe existence — is bounded, and that is what this suite does. The
 * decisive evidence for the ruling rather than the argument for it: **every P0 of round 22 was found by a
 * behavioural probe and none by reading catalogues.**
 *
 * THE ONE IDEA THAT MAKES THE KEY-SHAPE AXES UNNECESSARY. To prove no unique key omits the tenant column, this
 * suite does not enumerate keys at all: it **inserts tenant A's row verbatim under tenant B**. If any uniqueness
 * mechanism ignores the tenant, that insert collides with `23505`. One attack covers PRIMARY KEY, UNIQUE, unique
 * INDEX, an index whose tenant column is merely `INCLUDE`d (round 22's R22-1, invisible to `indkey`), and an
 * `EXCLUDE` constraint (R22-2, invisible to a `contype IN ('p','u','f')` query) — without naming any of them, and
 * it will cover whatever PostgreSQL 19 adds. Each of those defects is proven caught by an explicit case below.
 *
 * WHAT THE CATALOGUE IS STILL USED FOR, since it is not banned outright: **deciding what to attack and how to
 * build a valid row.** That is DISCOVERY, and a mistake in it makes an attack fail loudly rather than pass
 * silently — the opposite direction from a mistaken VERDICT, which is what every round-22 P0 was.
 *
 * ANTI-VACUITY, mandatory and asserted rather than assumed. `CLAUDE.md` § Gotchas records four separate controls
 * that silently did not run, one of them the integration suite skipping the entire tenancy proof and reporting
 * `OK`. So this suite refuses to pass over nothing:
 *   - every discovered relation must have been attacked, compared by NAME against the discovered set;
 *   - at least two tenants must actually hold rows;
 *   - at least one attack must be proven REFUSED;
 *   - and every relation carries a POSITIVE control — the runtime role must read its OWN rows. Without it, a
 *     relation that is simply empty or unreadable satisfies every "sees nothing across the boundary" assertion
 *     trivially, which is the exact shape of a vacuous pass.
 */
#[CoversNothing]
final class BehaviouralIsolationTest extends TestCase
{
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_behavioural_isolation_probe';

    private const TENANT_A = '01926b3c-0000-7000-8000-00000000000a';
    private const TENANT_B = '01926b3c-0000-7000-8000-00000000000b';

    /**
     * Values for columns a CHECK constraint restricts to a known set, keyed by column NAME.
     *
     * Keyed by name rather than by `table.column` so a new table reusing `type` or `currency` is populated without
     * an entry. Two values per column, one per tenant variant, so the two tenants' rows differ in every column
     * that can differ — which is what stops the unique probe below colliding with the attacking tenant's OWN row
     * and reporting a defect that is not there.
     *
     * A column this map does not cover gets a synthesised value from its TYPE. If a future CHECK constraint makes
     * that value illegal the insert fails, the seeding assertion names the table and PostgreSQL's own error, and
     * someone adds a line here. That is the correct direction: a new table arrives RED rather than unattacked.
     */
    private const array COLUMN_VALUES = [
        'type' => ['invoice', 'quote'],
        'state' => ['draft', 'issued'],
        'currency' => ['TND', 'EUR'],
        'vat_rounding_point' => ['per_line', 'per_rate_group'],
    ];

    private static ?\PDO $admin = null;

    public static function setUpBeforeClass(): void
    {
        self::createMigratedProbeDatabase(self::DATABASE);
        self::grantRuntimeDml();
    }

    /**
     * Give the runtime role the DML it has in production — and note what discovering this revealed.
     *
     * **The migration issues no `GRANT` at all**, and the privileges the runtime role actually runs with come from
     * `ALTER DEFAULT PRIVILEGES ... IN SCHEMA public` in `scripts/dev/provision-test-database.sh`. Those are
     * **per-database** catalogue entries, so they apply to `twes_in_test` and to nothing else — a freshly created
     * and migrated database gives the runtime role no access to any table. [Verified 2026-08-01: every attack here
     * failed with `permission denied for table document` until this method existed.]
     *
     * That fails CLOSED, so it is not a breach, and it is why no gate noticed: `schema-tenancy.php` connects as a
     * superuser and reads catalogues, so it never asks whether the application could use the schema at all. It is
     * still a deployment defect — a new environment migrates green and then cannot serve a request — and it is
     * recorded for the developer in `build-waves.plan.md` rather than fixed here, because a migration cannot know
     * the runtime role's NAME: that is deployment configuration, not schema.
     *
     * TRUNCATE is deliberately excluded, exactly as the provisioning script excludes it. It is never subject to row
     * security at any privilege level, so granting it here would hand the suite's own fixture the one privilege
     * GOAL 6 exists to prove absent — a fixture that grants what the test asserts is missing proves nothing.
     */
    private static function grantRuntimeDml(): void
    {
        $admin = self::admin();

        foreach (self::discoverTenantRelations($admin) as $relation) {
            $admin->exec(\sprintf(
                'GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO %s',
                self::qualifyQuoted($relation),
                self::quote(self::runtimeRole()),
            ));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropProbeDatabase(self::DATABASE);
        self::$admin = null;
    }

    /**
     * THE SUITE'S CENTRAL CASE: every relation discovery finds, attacked every way an attacker would want.
     *
     * One case rather than one per attack, because the attacks share an expensive fixture and because the thing
     * being asserted is a conjunction: a relation is isolated when EVERY goal is refused, and a report naming all
     * the findings at once is what a reader needs.
     */
    public function testNoAttackSucceedsAgainstAnyDiscoveredRelation(): void
    {
        $relations = self::discoverTenantRelations(self::admin());

        self::assertGreaterThanOrEqual(
            4,
            \count($relations),
            'Discovery found fewer tenant-owned relations than the first migration creates, so this suite would '
            . 'attack almost nothing. Either the probe database was not migrated or discovery is broken — both '
            . 'look identical to a passing run unless checked.',
        );

        $this->seedBothTenants($relations);

        $findings = [];
        $attacked = [];
        $refusals = 0;

        foreach ($relations as $relation) {
            $attacked[] = self::qualify($relation);
            $report = $this->attackRelation($relation);
            $findings = [...$findings, ...$report['findings']];
            $refusals += $report['refusals'];
        }

        self::assertSame(
            [],
            $findings,
            "An attack SUCCEEDED against tenant data. Each line is a reproduced breach:\n  - "
            . implode("\n  - ", $findings),
        );

        // COVERAGE, by name rather than by count: a relation discovery finds and the attack loop skips is exactly
        // the silent gap this suite exists to make impossible.
        self::assertSame(
            array_map(self::qualify(...), $relations),
            $attacked,
            'A discovered relation was not attacked.',
        );

        // ANTI-VACUITY. Without this, a run where nothing was refused because nothing was attempted passes.
        self::assertGreaterThan(
            0,
            $refusals,
            'Not one attack was REFUSED, so no evidence of isolation was produced. A suite that attempted nothing '
            . 'reports the same empty findings list as one that attempted everything and was refused.',
        );
    }

    /**
     * Both tenants hold rows, and the runtime role reads its OWN — the positive control, asserted separately.
     *
     * Separate from the case above so that a failure here is unambiguous. If the tables were empty, or unreadable
     * by the runtime role, every cross-tenant assertion in this suite would hold trivially: a relation nobody can
     * read leaks nothing. `CLAUDE.md` records four controls that silently did not run; this is the assertion that
     * keeps this one from becoming the fifth.
     */
    public function testBothTenantsHoldRowsThatTheirOwnTenantCanRead(): void
    {
        $relations = self::discoverTenantRelations(self::admin());
        $this->seedBothTenants($relations);
        $runtime = self::runtimeConnection();

        foreach ($relations as $relation) {
            if (!self::acceptsWrites($relation)) {
                continue;
            }

            foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
                $visible = self::boundQuery(
                    $runtime,
                    $tenant,
                    \sprintf('SELECT count(*) FROM %s', self::qualifyQuoted($relation)),
                );

                self::assertGreaterThan(
                    0,
                    $visible,
                    \sprintf(
                        'The runtime role bound to %s reads NO rows from %s, so every cross-tenant assertion about '
                        . 'this relation is vacuous. Either seeding did not happen or the policy does not admit the '
                        . 'runtime role — a policy for one command, or granted to another role, fails closed and '
                        . 'looks exactly like isolation.',
                        $tenant,
                        self::qualify($relation),
                    ),
                );
            }
        }
    }

    /**
     * An UNBOUND session reads nothing, WITHOUT error — which is what pins the `NULLIF` in the canonical policy.
     *
     * The fail-closed direction is the whole design: `current_setting(..., true)` is NULL when the tenant was
     * never set, so an unbound session matches no row rather than every row. But a session that has bound once and
     * rolled back leaves the setting as the EMPTY STRING rather than unset, and `''::uuid` raises `22P02`. So the
     * canonical expression wraps the read in `NULLIF(..., '')`, and this case is what makes that load-bearing:
     * removing the `NULLIF` turns this assertion from "0 rows" into an invalid-input error.
     *
     * [Verified 2026-08-01 while writing this suite: a policy WITHOUT the `NULLIF` raised
     * `SQLSTATE[22P02]: invalid input syntax for type uuid: ""` on exactly this read.] Both outcomes are safe —
     * neither leaks a row — but one leaves the table unusable after any rolled-back transaction, and that is a
     * defect no cross-tenant read would ever reveal.
     */
    public function testAnUnboundSessionReadsNothingAndDoesNotError(): void
    {
        $relations = self::discoverTenantRelations(self::admin());
        $this->seedBothTenants($relations);
        $runtime = self::runtimeConnection();

        // Bind and roll back FIRST, so the setting is the empty string rather than unset. Reading on a pristine
        // connection would exercise the NULL arm and pass with or without the NULLIF, which is how this case would
        // have looked green while proving nothing.
        $runtime->beginTransaction();
        self::bindTo($runtime, self::TENANT_A);
        $runtime->rollBack();

        foreach ($relations as $relation) {
            $table = self::qualifyQuoted($relation);

            try {
                $statement = $runtime->query(\sprintf('SELECT count(*) FROM %s', $table));
                self::assertNotFalse($statement, 'the unbound read returned no statement');
                $visible = (int) $statement->fetchColumn();
            } catch (\PDOException $e) {
                self::fail(\sprintf(
                    'An UNBOUND read of %s raised %s instead of returning no rows: %s. This is the canonical '
                    . "policy's NULLIF arm: after a rolled-back transaction the tenant setting is the empty "
                    . 'string, and casting that to uuid raises. Fails closed, but the relation is unusable.',
                    self::qualify($relation),
                    $e->errorInfo[0] ?? 'an error',
                    $e->getMessage(),
                ));
            }

            self::assertSame(
                0,
                $visible,
                \sprintf(
                    'An UNBOUND session reads %d row(s) from %s. Forgetting to bind must show NOTHING rather than '
                    . 'everything: that fail-closed direction is the entire design of this scheme.',
                    $visible,
                    self::qualify($relation),
                ),
            );
        }
    }

    /**
     * The OWNER connection is subject to policies too — which is what `FORCE ROW LEVEL SECURITY` buys.
     *
     * Behavioural replacement for the gate's `relforcerowsecurity` read. Migrations connect as this role, so
     * without FORCE every migration and any support tooling reads every tenant, and the schema gate's own
     * catalogue check could only ever say the flag was set rather than that it worked.
     */
    public function testTheOwningRoleIsAlsoConfinedByThePolicies(): void
    {
        $relations = self::discoverTenantRelations(self::admin());
        $this->seedBothTenants($relations);

        $owner = self::connectionTo(self::DATABASE, self::ownerRole(), self::ownerPassword());

        foreach ($relations as $relation) {
            $crossTenant = self::boundQuery(
                $owner,
                self::TENANT_B,
                \sprintf(
                    'SELECT count(*) FROM %s WHERE %s = %s',
                    self::qualifyQuoted($relation),
                    self::quote(PostgresRowLevelSecurityIsolation::TENANT_COLUMN),
                    self::literal(self::TENANT_A),
                ),
            );

            self::assertSame(
                0,
                $crossTenant,
                \sprintf(
                    'The OWNING role "%s", bound to tenant B, reads %d of tenant A\'s rows from %s. Policies do '
                    . 'not apply to a table\'s owner without FORCE ROW LEVEL SECURITY, and migrations run as this '
                    . 'role.',
                    self::ownerRole(),
                    $crossTenant,
                    self::qualify($relation),
                ),
            );
        }
    }

    /**
     * The runtime role cannot reach a role that bypasses policies — attacked with `SET ROLE`, not read from
     * `pg_roles`.
     *
     * Behavioural replacement for the gate's role-attribute axis, which was a round-22 P0 in its own right:
     * `rolsuper` and `rolbypassrls` are NOT inherited, so a role that is merely a MEMBER of a bypassing role
     * reads `f/f/f` in its own catalogue row, passed that check, and reached the privilege with one `SET ROLE`.
     * Attempting the escalation cannot make that mistake.
     */
    public function testTheRuntimeRoleCannotEscalateToARoleThatBypassesPolicies(): void
    {
        $relations = self::discoverTenantRelations(self::admin());
        $this->seedBothTenants($relations);

        $admin = self::admin();
        // Every role this connection could possibly become, asked of PostgreSQL rather than derived from
        // attributes: `pg_has_role(..., 'MEMBER')` is the predicate that authorises SET ROLE.
        $candidates = $admin->query(\sprintf(
            'SELECT r.rolname FROM pg_roles r WHERE %s AND r.rolname <> %s ORDER BY 1',
            PostgresRowLevelSecurityIsolation::roleIsReachableBySql(self::literal(self::runtimeRole()), 'r.oid'),
            self::literal(self::runtimeRole()),
        ));

        self::assertNotFalse($candidates, 'could not enumerate reachable roles');

        /** @var list<string> $reachable */
        $reachable = $candidates->fetchAll(\PDO::FETCH_COLUMN);
        $runtime = self::runtimeConnection();
        $relation = $relations[0];

        $attempted = [];
        $refused = [];

        foreach ($reachable as $role) {
            $runtime->beginTransaction();

            try {
                self::bindTo($runtime, self::TENANT_B);

                try {
                    $runtime->exec(\sprintf('SET ROLE %s', self::quote($role)));
                    $attempted[] = $role;
                } catch (\PDOException $e) {
                    // REFUSED, which is the desired outcome and not an error in the test. `pg_has_role(...,
                    // 'MEMBER')` is true for a membership granted `WITH SET FALSE`, and PostgreSQL then still
                    // refuses `SET ROLE` -- the `twes_unsettable` and chain roles the provisioning script creates
                    // exist precisely to produce that shape. An assertion that treated this as a failure would
                    // make the suite red on a CORRECTLY provisioned cluster.
                    $refused[] = $role . ' (' . self::firstLineOf($e->getMessage()) . ')';

                    continue;
                }

                try {
                    $leaked = (int) $runtime->query(\sprintf(
                        'SELECT count(*) FROM %s WHERE %s = %s',
                        self::qualifyQuoted($relation),
                        self::quote(PostgresRowLevelSecurityIsolation::TENANT_COLUMN),
                        self::literal(self::TENANT_A),
                    ))->fetchColumn();
                } catch (\PDOException $e) {
                    // The escalation SUCCEEDED but gained nothing: the role reached holds no privilege on the
                    // relation. Still a refusal of the attacker's goal, and the common case for the NOLOGIN probe
                    // roles the provisioning script creates, none of which is granted anything on any table.
                    $refused[] = $role . ' (reached, then ' . self::firstLineOf($e->getMessage()) . ')';
                    array_pop($attempted);

                    continue;
                }

                self::assertSame(
                    0,
                    $leaked,
                    \sprintf(
                        'The runtime role reached "%s" with SET ROLE and then read %d of tenant A\'s rows from %s '
                        . 'while bound to tenant B. A role that bypasses row-level security must never be '
                        . 'reachable from the application\'s role — and note the attributes on its own catalogue '
                        . 'row may look clean, because rolsuper and rolbypassrls are not inherited.',
                        $role,
                        $leaked,
                        self::qualify($relation),
                    ),
                );
            } finally {
                $runtime->rollBack();
            }
        }

        // An empty candidate list is the DESIRED state rather than an anti-vacuity failure -- a runtime role that
        // can become nothing is what production wants. Asserted as a conjunction so the reader can tell the two
        // apart from the failure message: every candidate was either escalated-into-and-still-confined, or refused.
        self::assertSame(
            \count($reachable),
            \count($attempted) + \count($refused),
            \sprintf(
                "Some reachable role was neither escalated into nor refused.\n  candidates: %s\n  escalated: %s\n"
                . '  refused: %s',
                implode(', ', $reachable) ?: 'none',
                implode(', ', $attempted) ?: 'none',
                implode(', ', $refused) ?: 'none',
            ),
        );
    }

    // ---------------------------------------------------------------------------------------------------------
    // PROOFS THAT THE DELETED GATE AXES ARE COVERED. Each case introduces the exact defect one retired
    // catalogue judgement used to look for, and requires the ATTACK to report it. Without these, deleting those
    // axes would be an act of faith.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * R22-1: a unique index presenting the tenant column as an `INCLUDE` payload rather than a key column.
     *
     * `pg_index.indkey` spans key AND included columns, and only the first `indnkeyatts` participate in
     * uniqueness — so the gate's key check saw `company_id` present and passed, while uniqueness was enforced on
     * the other column across every tenant. A cross-tenant existence oracle, and a denial of service on another
     * tenant's numbering. The attack needs to know none of that.
     */
    public function testTheUniqueProbeCatchesAnIncludeColumnUniqueIndex(): void
    {
        $findings = $this->attackDefectiveRelation(
            'include_column_index',
            [
                'CREATE TABLE include_column_index (company_id uuid NOT NULL, code text NOT NULL)',
                'CREATE UNIQUE INDEX include_column_index_defect ON include_column_index (code)'
                . ' INCLUDE (company_id)',
            ],
        );

        self::assertNotSame([], $findings, 'an INCLUDE-column unique index went undetected');
        self::assertStringContainsString('unique', strtolower(implode(' ', $findings)));
    }

    /**
     * R22-2: an `EXCLUDE` constraint, which the gate's key query could not see from either direction.
     *
     * `contype` is `'x'`, absent from its `IN ('p','u','f')` list, and `pg_index.indisunique` is FALSE for the
     * backing index — so an exclusion constraint enforcing `=` on a non-tenant column was invisible to both
     * halves at once while behaving exactly like a cross-tenant unique key.
     */
    public function testTheUniqueProbeCatchesAnExclusionConstraintOmittingTheTenant(): void
    {
        $findings = $this->attackDefectiveRelation(
            'exclusion_constraint',
            [
                'CREATE TABLE exclusion_constraint (company_id uuid NOT NULL, code text NOT NULL)',
                'ALTER TABLE exclusion_constraint ADD CONSTRAINT exclusion_constraint_defect'
                . ' EXCLUDE (code WITH =)',
            ],
        );

        self::assertNotSame([], $findings, 'an EXCLUDE constraint omitting the tenant went undetected');
    }

    /**
     * A relation with NO row-level security at all — the defect this whole gate was written for.
     *
     * Kept as a behavioural case even though the schema gate still checks it, because the gate's version reads a
     * catalogue flag and this one reads another tenant's rows.
     */
    public function testTheReadAttackCatchesARelationWithNoRowLevelSecurity(): void
    {
        $findings = $this->attackDefectiveRelation(
            'unpoliced_table',
            ['CREATE TABLE unpoliced_table (company_id uuid NOT NULL, code text NOT NULL)'],
            policed: false,
        );

        self::assertNotSame([], $findings, 'a table with no row-level security went undetected');
        self::assertStringContainsString("tenant A's", implode(' ', $findings));
    }

    /**
     * R22-4: a MATERIALIZED VIEW over tenant data, which can carry no policy at all.
     *
     * The gate refused this by relkind. The attack reads tenant A's rows out of it while bound to tenant B, which
     * is the same verdict reached by observation — and it also covers a foreign table, and whatever relkind
     * PostgreSQL adds next, without either being named.
     */
    public function testTheReadAttackCatchesAMaterializedViewOverTenantData(): void
    {
        // The base table is CORRECTLY policed, so the matview is the only defect in play. A test where everything
        // leaks cannot tell you which thing was caught.
        $findings = $this->attackDefectiveRelation(
            'leaky_matview',
            [
                'CREATE TABLE matview_base (company_id uuid NOT NULL, code text NOT NULL)',
                'CREATE MATERIALIZED VIEW leaky_matview AS SELECT * FROM matview_base',
            ],
            seedInto: 'matview_base',
            afterSeed: ['REFRESH MATERIALIZED VIEW leaky_matview'],
        );

        self::assertNotSame([], $findings, 'a materialized view over tenant data went undetected');
    }

    /**
     * R22-5: a plain VIEW owned by a role that can bypass policies, and NOT `security_invoker`.
     *
     * For such a view PostgreSQL evaluates the base table's row security as the VIEW'S OWNER, so it returns every
     * tenant to anyone who can select from it. `FORCE` binds the table's owner and says nothing about a third role
     * owning a view over it. The gate reached this only after a docblock had claimed for months that a view
     * "stays scoped — verified by a reviewer who tried to break it and could not"; the attack does not read
     * docblocks.
     */
    public function testTheReadAttackCatchesAViewOwnedByABypassingRole(): void
    {
        $bypassRole = getenv('TWES_TEST_DB_BYPASS_USER') ?: 'twes_bypass';

        // The base table is policed by `policySqlFor()` -- the real thing, not a hand-written copy -- so the ONLY
        // defect is the view's owner. `twes_bypass` needs SELECT on the base table because a non-`security_invoker`
        // view reads it AS ITS OWNER, which is the very mechanism under test.
        $findings = $this->attackDefectiveRelation(
            'leaky_view',
            [
                'CREATE TABLE view_base (company_id uuid NOT NULL, code text NOT NULL)',
                'CREATE VIEW leaky_view AS SELECT * FROM view_base',
                \sprintf('ALTER VIEW leaky_view OWNER TO %s', $bypassRole),
            ],
            seedInto: 'view_base',
            extraGrants: [\sprintf('GRANT SELECT ON view_base TO %s', $bypassRole)],
        );

        self::assertNotSame([], $findings, 'a view owned by a bypassing role went undetected');
    }

    // ---------------------------------------------------------------------------------------------------------
    // THE ATTACKS
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Every attacker goal, against one relation.
     *
     * @param array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string,
     *              nullable: bool}>, fks: list<array{columns: list<string>, parent: string,
     *              parentColumns: list<string>}>} $relation
     *
     * @return array{findings: list<string>, refusals: int}
     */
    private function attackRelation(array $relation): array
    {
        $runtime = self::runtimeConnection();
        $table = self::qualifyQuoted($relation);
        $tenantColumn = self::quote(PostgresRowLevelSecurityIsolation::TENANT_COLUMN);
        $name = self::qualify($relation);
        $findings = [];
        $refusals = 0;

        // GOAL 1: READ another tenant's rows. The one attack every relkind gets, because a view and a
        // materialized view cannot be written to but leak just as completely.
        $leaked = self::boundQuery($runtime, self::TENANT_B, \sprintf(
            'SELECT count(*) FROM %s WHERE %s = %s',
            $table,
            $tenantColumn,
            self::literal(self::TENANT_A),
        ));

        if (0 !== $leaked) {
            $findings[] = \sprintf(
                "%s: bound to tenant B, the runtime role READ %d of tenant A's row(s).",
                $name,
                $leaked,
            );
        } else {
            ++$refusals;
        }

        if (!self::acceptsWrites($relation)) {
            return ['findings' => $findings, 'refusals' => $refusals];
        }

        $columns = array_map(static fn(array $c): string => $c['name'], $relation['columns']);

        // GOAL 2: WRITE a row into another tenant. Refused by the policy's WITH CHECK half.
        //
        // Its own columns from variant 'b' so it cannot collide with tenant A's existing row, but its PARENT
        // reference from variant 'a' -- the one tenant A was seeded with -- so the foreign key is satisfiable and
        // the policy is the only thing that can refuse it. Without that split PostgreSQL raises 23503 first and the
        // attack proves nothing while looking refused.
        $crossTenantRow = self::rowFor($relation, self::TENANT_A, 'b', parentVariant: 'a');
        $insert = self::attempt($runtime, self::TENANT_B, self::insertSql($relation, $crossTenantRow));

        if ($insert['ok']) {
            $findings[] = \sprintf('%s: bound to tenant B, the runtime role INSERTED a row owned by tenant A.', $name);
        } elseif (!self::isRowSecurityRefusal($insert)) {
            // Refused, but for an unrelated reason -- so it is not evidence of isolation. A CHECK violation
            // (23514) or a missing privilege reads exactly like a policy refusal to anything that only looks at
            // "did it fail", which is why the SQLSTATE and the message are both examined.
            $findings[] = \sprintf(
                '%s: the cross-tenant INSERT was refused by %s ("%s") rather than by the row-level security '
                . 'policy, so it proves nothing about isolation. Fix the fixture for this relation.',
                $name,
                $insert['sqlstate'],
                $insert['message'],
            );
        } else {
            ++$refusals;
        }

        // GOAL 3: MODIFY another tenant's row. Note the verified subtlety: this is NOT refused with an error --
        // the row is simply invisible, so the UPDATE reports zero rows affected. An assertion written to expect an
        // exception here would pass for the wrong reason on a leaking table.
        $update = self::attempt($runtime, self::TENANT_B, \sprintf(
            'UPDATE %s SET %s = %s WHERE %s = %s',
            $table,
            $tenantColumn,
            $tenantColumn,
            $tenantColumn,
            self::literal(self::TENANT_A),
        ));

        if ($update['ok'] && 0 !== $update['affected']) {
            $findings[] = \sprintf(
                "%s: bound to tenant B, the runtime role UPDATED %d of tenant A's row(s).",
                $name,
                $update['affected'],
            );
        } else {
            ++$refusals;
        }

        // GOAL 4: RE-PARENT one's own row into another tenant.
        //
        // A PostgreSQL semantic worth knowing, measured rather than assumed while proving this goal load-bearing:
        // **when a SELECT policy exists, an UPDATE's NEW row must also satisfy that policy's USING clause.** So the
        // canonical USING half acts as a second WITH CHECK, and re-parenting stays refused even with
        // `WITH CHECK (true)`. [Verified 2026-08-01 on five policy configurations: `FOR ALL` with `WITH CHECK
        // (true)` → 42501; `FOR SELECT USING (canonical)` plus `FOR UPDATE ... WITH CHECK (true)` → 42501; the same
        // with `FOR SELECT USING (true)` → succeeds, 1 row moved; UPDATE policy alone with no SELECT policy →
        // 0 rows, the row being invisible.]
        //
        // The consequence for this suite, stated because it is a real limit: GOAL 4 cannot be broken WITHOUT also
        // breaking GOAL 1, so no mutant isolates it. That is defence in depth in the schema rather than a weakness
        // in the attack — and the goal is still attempted, because a schema with a widened SELECT half would leave
        // it as the only thing standing between a tenant and giving its rows away.
        $reparent = self::attempt($runtime, self::TENANT_A, \sprintf(
            'UPDATE %s SET %s = %s WHERE %s = %s',
            $table,
            $tenantColumn,
            self::literal(self::TENANT_B),
            $tenantColumn,
            self::literal(self::TENANT_A),
        ));

        if ($reparent['ok']) {
            $findings[] = \sprintf(
                '%s: bound to tenant A, the runtime role RE-PARENTED %d of its own row(s) into tenant B. The '
                . 'policy needs a WITH CHECK half, or a FOR ALL policy whose USING half PostgreSQL reuses as one.',
                $name,
                $reparent['affected'],
            );
            // STRICT: only the POLICY may refuse this, and an earlier draft of this line was wrong in a way worth
            // recording. It also accepted `23503`, on the reasoning that a composite foreign key refuses a
            // re-parented row too because moving the parent orphans its children. True, and it makes the assertion
            // useless: mutant M5 replaced `WITH CHECK (canonical)` with `WITH CHECK (true)` and the suite stayed
            // GREEN, because referential integrity caught what the policy no longer did. That tolerance was added
            // speculatively rather than from an observed failure — the anti-bandaid gate's exact target — and
            // removing it kills M5. A childless tenant table has no foreign key to hide behind.
        } elseif (!self::isRowSecurityRefusal($reparent)) {
            $findings[] = \sprintf(
                '%s: the re-parenting UPDATE was refused by %s ("%s") rather than by the policy, so it proves '
                . 'nothing.',
                $name,
                $reparent['sqlstate'],
                $reparent['message'],
            );
        } else {
            ++$refusals;
        }

        // GOAL 5: DELETE another tenant's rows. Invisible rather than refused, as with GOAL 3.
        $delete = self::attempt($runtime, self::TENANT_B, \sprintf(
            'DELETE FROM %s WHERE %s = %s',
            $table,
            $tenantColumn,
            self::literal(self::TENANT_A),
        ));

        if ($delete['ok'] && 0 !== $delete['affected']) {
            $findings[] = \sprintf(
                "%s: bound to tenant B, the runtime role DELETED %d of tenant A's row(s).",
                $name,
                $delete['affected'],
            );
        } else {
            ++$refusals;
        }

        // GOAL 6: ERASE everything. TRUNCATE ignores row-level security completely, so holding it defeats every
        // policy on the table in one statement -- round 5 did exactly that while the isolation check reported the
        // connection clean.
        //
        // TWO ATTEMPTS, and the plain one alone was a survivor. `TRUNCATE document` on the real schema is refused
        // with `0A000 cannot truncate a table referenced in a foreign key constraint` once the role HOLDS the
        // privilege — so mutant M4 granted TRUNCATE, the statement still failed, and this goal counted it as a
        // refusal while the role could erase every tenant's rows with one added keyword. The CASCADE form is what
        // an attacker would actually type, and it is judged strictly: only a PRIVILEGE refusal counts, so a
        // statement that fails for any other reason is reported as unanswered rather than banked as evidence.
        $truncate = self::attempt($runtime, self::TENANT_B, \sprintf('TRUNCATE %s', $table));

        if (!$truncate['ok'] && '42501' !== $truncate['sqlstate']) {
            $truncate = self::attempt($runtime, self::TENANT_B, \sprintf('TRUNCATE %s CASCADE', $table));
        }

        if ($truncate['ok']) {
            $findings[] = \sprintf(
                '%s: the runtime role TRUNCATED the relation. TRUNCATE ignores row-level security entirely, so '
                . "this erases every tenant's rows in one statement whatever the policies say.",
                $name,
            );
        } elseif ('42501' !== $truncate['sqlstate']) {
            $findings[] = \sprintf(
                '%s: the TRUNCATE attempt was refused by %s ("%s") rather than for want of the privilege, so '
                . 'whether the runtime role holds TRUNCATE went unanswered.',
                $name,
                $truncate['sqlstate'],
                $truncate['message'],
            );
        } else {
            ++$refusals;
        }

        // GOAL 7: PROBE another tenant's data through a uniqueness collision -- the attack that makes every
        // key-shape catalogue check unnecessary. Tenant A's exact row, re-tenanted to B and re-parented to B's own
        // parent rows. It must SUCCEED: a collision means some uniqueness mechanism ignores the tenant column,
        // which is both an existence oracle and a denial of service on the other tenant's numbering.
        // Tenant A's own column values (variant 'a'), under tenant B, pointing at tenant B's parent rows.
        //
        // A stated limit rather than a hidden one: on a CHILD relation the parent reference is itself part of the
        // key and has to be re-pointed at B's parent, so the probe is weaker there. That turns out to be correct
        // rather than a gap — a child key containing a globally unique parent UUID cannot collide across tenants
        // however the key is composed. The probe bites hardest on a ROOT relation, which is where a tenant-scoped
        // counter or a human-facing number lives, and the defective-relation cases below prove the mechanism
        // fires.
        $probe = self::rowFor($relation, self::TENANT_B, 'a', parentVariant: 'b');
        $collision = self::attempt($runtime, self::TENANT_B, self::insertSql($relation, $probe));

        if (!$collision['ok'] && '23505' === $collision['sqlstate']) {
            $findings[] = \sprintf(
                '%s: tenant B cannot store a value tenant A already uses — "%s". Some uniqueness mechanism on '
                . 'this relation does not include "%s", and uniqueness checks run with row-level security '
                . 'BYPASSED, so it is enforced across EVERY tenant. That is a cross-tenant existence oracle and a '
                . "denial of service on another tenant's numbering.",
                $name,
                $collision['message'],
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
            );
        } elseif (!$collision['ok']) {
            $findings[] = \sprintf(
                '%s: the uniqueness probe could not be attempted — the insert failed with %s ("%s") for an '
                . 'unrelated reason, so this relation went unprobed. A silent skip here is how an %s-column index '
                . 'stayed invisible for twenty rounds.',
                $name,
                $collision['sqlstate'],
                $collision['message'],
                'INCLUDE',
            );
        } else {
            ++$refusals;
        }

        // GOAL 8: REFERENCE another tenant's row. Tenant B's own row, but with its parent reference left pointing
        // at tenant A's parent. A composite foreign key refuses this with 23503; a single-column one accepts it,
        // and `ON DELETE CASCADE` then reaches across the boundary to delete.
        foreach ($relation['fks'] as $fk) {
            if (!\in_array(PostgresRowLevelSecurityIsolation::TENANT_COLUMN, $columns, true)) {
                continue;
            }

            $reference = self::rowFor($relation, self::TENANT_B, 'b');
            $parentOfA = self::rowFor(self::relationNamed($fk['parent']), self::TENANT_A, 'a');

            foreach ($fk['columns'] as $index => $local) {
                if (PostgresRowLevelSecurityIsolation::TENANT_COLUMN === $local) {
                    continue;
                }

                $reference[$local] = $parentOfA[$fk['parentColumns'][$index]] ?? $reference[$local];
            }

            $crossReference = self::attempt($runtime, self::TENANT_B, self::insertSql($relation, $reference));

            if ($crossReference['ok']) {
                $findings[] = \sprintf(
                    '%s: tenant B inserted a row REFERENCING tenant A\'s row in %s through "%s". Make the foreign '
                    . 'key composite on both sides — a single-column one lets a tenant reference another\'s row, '
                    . 'and ON DELETE CASCADE then deletes across the boundary.',
                    $name,
                    $fk['parent'],
                    implode(', ', $fk['columns']),
                );
            } else {
                ++$refusals;
            }
        }

        return ['findings' => $findings, 'refusals' => $refusals];
    }

    /**
     * Build a deliberately defective relation, attack it, and return what the attack found.
     *
     * The mechanism behind every "the attack catches what the deleted axis caught" case above. Seeds with the
     * SUPERUSER, which bypasses row security and so can write both tenants' rows regardless of the policies under
     * test — the fixture must be able to create the dangerous state, or it cannot detect it.
     *
     * SEEDED THROUGH {@see self::rowFor()} RATHER THAN WITH LITERALS, and the first draft of this method got that
     * wrong in the way `CLAUDE.md` § "No fixture leakage" describes. It inserted `'code-a'` and `'code-b'` by hand
     * while the uniqueness probe re-presented `rowFor()`'s synthesised `'value-a'` — so the probe offered a value
     * tenant A had never stored, collided with nothing, and BOTH key-shape cases reported "went undetected"
     * against genuinely defective schemas. A fixture whose values are written independently of the code under test
     * does not exercise it.
     *
     * @param list<string> $ddl
     * @param list<string> $afterSeed
     * @param list<string> $extraGrants
     *
     * @return list<string>
     */
    private function attackDefectiveRelation(
        string $name,
        array $ddl,
        bool $policed = true,
        ?string $seedInto = null,
        array $afterSeed = [],
        array $extraGrants = [],
    ): array {
        $admin = self::admin();
        $target = $seedInto ?? $name;
        $created = [];

        foreach ($ddl as $statement) {
            $admin->exec($statement);

            if (1 === preg_match('/^CREATE (TABLE|MATERIALIZED VIEW|VIEW) (\w+)/', $statement, $m)) {
                $created[] = [$m[1], $m[2]];
            }
        }

        try {
            if ($policed) {
                foreach (PostgresRowLevelSecurityIsolation::policySqlFor($target) as $statement) {
                    $admin->exec($statement);
                }
            }

            $admin->exec(\sprintf('ALTER TABLE %s OWNER TO %s', $target, self::ownerRole()));

            foreach ($created as [$kind, $relation]) {
                // A view or materialized view is read-attacked only, so SELECT is the whole requirement. Writing
                // to one is not an attacker goal this suite claims to cover.
                $admin->exec(\sprintf(
                    'GRANT %s ON %s TO %s',
                    'TABLE' === $kind ? 'SELECT, INSERT, UPDATE, DELETE' : 'SELECT',
                    $relation,
                    self::runtimeRole(),
                ));
            }

            foreach ($extraGrants as $statement) {
                $admin->exec($statement);
            }

            // Discover FIRST, then seed through the same synthesiser the attacks use -- see the docblock.
            self::$relations = null;
            $seedTarget = self::relationNamed($target);
            $this->seedBothTenants([$seedTarget]);

            foreach ($afterSeed as $statement) {
                $admin->exec($statement);
            }

            return $this->attackRelation(self::relationNamed($name))['findings'];
        } finally {
            self::$relations = null;

            foreach (array_reverse($created) as [$kind, $relation]) {
                $admin->exec(\sprintf('DROP %s IF EXISTS %s CASCADE', $kind, $relation));
            }
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // DISCOVERY AND FIXTURES. The catalogue is read HERE and only here -- to decide what to attack and how to
    // build a valid row. A mistake in this half makes an attack fail loudly; a mistake in a VERDICT passes
    // silently, which is the asymmetry the whole restructure rests on.
    // ---------------------------------------------------------------------------------------------------------

    /** @var ?list<array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{columns: list<string>, parent: string, parentColumns: list<string>}>}> */
    private static ?array $relations = null;

    /**
     * Every relation carrying the tenant column, in an order where a parent precedes its children.
     *
     * The relkind set matches the schema gate's on purpose: an ordinary table, a partitioned one, a view, a
     * materialized view and a foreign table can all hold tenant data, and the last three are precisely the ones a
     * catalogue check keeps getting wrong.
     *
     * @return list<array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{columns: list<string>, parent: string, parentColumns: list<string>}>}>
     */
    private static function discoverTenantRelations(\PDO $admin): array
    {
        if (null !== self::$relations) {
            return self::$relations;
        }

        // CONCATENATED rather than sprintf'd: the `LIKE 'pg\_toast%'` patterns below contain per-cent signs, which
        // sprintf reads as format specifiers and rejects with "Unknown format specifier".
        $statement = $admin->query(
            'SELECT n.nspname AS schema, c.relname AS name, c.relkind AS kind,'
            . " (SELECT json_agg(json_build_object('name', a.attname, 'type', format_type(a.atttypid,"
            . " a.atttypmod), 'nullable', NOT a.attnotnull) ORDER BY a.attnum)"
            . '  FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped)'
            . ' AS columns,'
            . " (SELECT coalesce(json_agg(json_build_object('columns', ("
            . '     SELECT json_agg(att.attname ORDER BY k.ord) FROM unnest(con.conkey)'
            . '     WITH ORDINALITY AS k(attnum, ord)'
            . '     JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = k.attnum'
            . "   ), 'parent', pc.relname, 'parentColumns', ("
            . '     SELECT json_agg(att.attname ORDER BY k.ord) FROM unnest(con.confkey)'
            . '     WITH ORDINALITY AS k(attnum, ord)'
            . '     JOIN pg_attribute att ON att.attrelid = con.confrelid AND att.attnum = k.attnum'
            . "   ))), '[]')"
            . '  FROM pg_constraint con JOIN pg_class pc ON pc.oid = con.confrelid'
            . "  WHERE con.conrelid = c.oid AND con.contype = 'f') AS fks"
            . ' FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace'
            . " WHERE c.relkind IN ('r', 'p', 'm', 'f', 'v')"
            . "   AND n.nspname NOT IN ('pg_catalog', 'information_schema')"
            . "   AND n.nspname NOT LIKE 'pg\\_toast%' AND n.nspname NOT LIKE 'pg\\_temp%'"
            . '   AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid'
            . '     AND a.attname = ' . self::literal(PostgresRowLevelSecurityIsolation::TENANT_COLUMN)
            . '     AND a.attnum > 0 AND NOT a.attisdropped)'
            . ' ORDER BY 1, 2',
        );

        self::assertNotFalse($statement, 'discovery query failed');

        $relations = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            /** @var list<array{name: string, type: string, nullable: bool}> $columns */
            $columns = json_decode((string) $row['columns'], true, 512, \JSON_THROW_ON_ERROR);
            /** @var list<array{columns: list<string>, parent: string, parentColumns: list<string>}> $fks */
            $fks = json_decode((string) $row['fks'], true, 512, \JSON_THROW_ON_ERROR);

            $relations[] = [
                'schema' => (string) $row['schema'],
                'name' => (string) $row['name'],
                'kind' => (string) $row['kind'],
                'columns' => $columns,
                'fks' => $fks,
            ];
        }

        return self::$relations = self::parentsFirst($relations);
    }

    /**
     * Order relations so a parent is seeded before its children.
     *
     * A stable insertion sort rather than a full topological sort: the graph here is shallow, and the ordering
     * only needs to be good enough that a child's parent row exists when the child is inserted. A cycle cannot
     * hang this — each relation is placed exactly once.
     *
     * @param list<array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{columns: list<string>, parent: string, parentColumns: list<string>}>}> $relations
     *
     * @return list<array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{columns: list<string>, parent: string, parentColumns: list<string>}>}>
     */
    private static function parentsFirst(array $relations): array
    {
        $ordered = [];
        $placed = [];

        // At most one pass per relation, so a cyclic graph terminates with the remainder appended in catalogue
        // order rather than looping.
        for ($pass = 0; $pass <= \count($relations); ++$pass) {
            foreach ($relations as $relation) {
                if (isset($placed[$relation['name']])) {
                    continue;
                }

                foreach ($relation['fks'] as $fk) {
                    $isSelfReference = $fk['parent'] === $relation['name'];
                    $parentIsPending = !isset($placed[$fk['parent']])
                        && [] !== array_filter($relations, static fn(array $r): bool => $r['name'] === $fk['parent']);

                    if (!$isSelfReference && $parentIsPending) {
                        continue 2;
                    }
                }

                $ordered[] = $relation;
                $placed[$relation['name']] = true;
            }
        }

        foreach ($relations as $relation) {
            if (!isset($placed[$relation['name']])) {
                $ordered[] = $relation;
            }
        }

        return $ordered;
    }

    /**
     * @return array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{columns: list<string>, parent: string, parentColumns: list<string>}>}
     */
    private static function relationNamed(string $name, bool $refresh = false): array
    {
        if ($refresh) {
            self::$relations = null;
        }

        foreach (self::discoverTenantRelations(self::admin()) as $relation) {
            if ($relation['name'] === $name) {
                return $relation;
            }
        }

        self::fail(\sprintf('discovery did not find the relation "%s"', $name));
    }

    /**
     * Insert one row per relation for both tenants, as the SUPERUSER.
     *
     * The superuser bypasses row security, so it can write both tenants' rows whatever the policies say. That is
     * required rather than convenient: a fixture built through the runtime role could only ever create the state
     * the policies already allow, and would therefore be unable to set up the cross-tenant reads this suite
     * exists to attempt.
     *
     * Idempotent, because several cases call it: an existing row is left alone rather than duplicated.
     *
     * @param list<array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{columns: list<string>, parent: string, parentColumns: list<string>}>}> $relations
     */
    private function seedBothTenants(array $relations): void
    {
        $admin = self::admin();

        foreach ([[self::TENANT_A, 'a'], [self::TENANT_B, 'b']] as [$tenant, $variant]) {
            foreach ($relations as $relation) {
                if (!self::acceptsWrites($relation)) {
                    continue;
                }

                $row = self::rowFor($relation, $tenant, $variant);

                try {
                    $admin->exec(self::insertSql($relation, $row) . ' ON CONFLICT DO NOTHING');
                } catch (\PDOException $e) {
                    self::fail(\sprintf(
                        "Could not seed %s for tenant %s: %s\nThe synthesised row was: %s\nA column restricted by "
                        . 'a CHECK constraint needs an entry in self::COLUMN_VALUES, keyed by column name. This '
                        . 'FAILS rather than skipping on purpose — a relation that cannot be seeded is a relation '
                        . 'that goes unattacked, and an unattacked relation passes every assertion in this suite.',
                        self::qualify($relation),
                        $variant,
                        $e->getMessage(),
                        json_encode($row, \JSON_THROW_ON_ERROR),
                    ));
                }
            }
        }
    }

    /**
     * A complete, valid row for one relation and one tenant.
     *
     * DETERMINISTIC in the relation, column, tenant variant — so the same call in a seeding pass and in an attack
     * yields the same row, which is what lets the uniqueness probe re-present tenant A's exact values under
     * tenant B without recording them anywhere.
     *
     * EVERY column is given a value, including nullable ones. `document.number` is nullable and participates in
     * the unique index over `(company_id, type, number)`; left NULL it would never collide, and the uniqueness
     * probe on the project's one nullable key column would be silently vacuous. Note this deliberately produces
     * rows the DOMAIN forbids — a draft document carrying a number — which is correct here, because the subject
     * under test is the schema's isolation rather than the aggregate's invariants.
     *
     * THE PARENT VARIANT IS SEPARATE FROM THE ROW'S OWN, and that separation is what makes the attacks
     * well-formed rather than accidentally self-defeating. A cross-tenant INSERT into a CHILD relation has to
     * satisfy the foreign key, or PostgreSQL refuses it with `23503` before the policy is ever consulted — and an
     * attack refused by referential integrity is not evidence about isolation. So GOAL 2 asks for the row's own
     * columns from one variant (so it does not collide with the tenant's existing row) and its parent reference
     * from the variant that tenant was actually seeded with.
     *
     * @param array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{columns: list<string>, parent: string, parentColumns: list<string>}>} $relation
     *
     * @return array<string, string>
     */
    private static function rowFor(
        array $relation,
        string $tenant,
        string $variant,
        ?string $parentVariant = null,
    ): array {
        $row = [];

        foreach ($relation['columns'] as $column) {
            $row[$column['name']] = PostgresRowLevelSecurityIsolation::TENANT_COLUMN === $column['name']
                ? $tenant
                : self::scalarFor($column, $variant);
        }

        // FK columns take the PARENT's value, so the row is referentially valid. Resolved recursively, which is
        // what makes a grandchild relation work without special handling.
        foreach ($relation['fks'] as $fk) {
            if ($fk['parent'] === $relation['name']) {
                continue;
            }

            $parent = self::rowFor(self::relationNamed($fk['parent']), $tenant, $parentVariant ?? $variant);

            foreach ($fk['columns'] as $index => $local) {
                if (isset($fk['parentColumns'][$index], $parent[$fk['parentColumns'][$index]])) {
                    $row[$local] = $parent[$fk['parentColumns'][$index]];
                }
            }
        }

        return $row;
    }

    /**
     * A value for one column, from its declared type — or from COLUMN_VALUES when a CHECK constraint restricts it.
     *
     * @param array{name: string, type: string, nullable: bool} $column
     */
    private static function scalarFor(array $column, string $variant): string
    {
        $index = 'a' === $variant ? 0 : 1;

        if (isset(self::COLUMN_VALUES[$column['name']])) {
            return self::COLUMN_VALUES[$column['name']][$index];
        }

        $type = strtolower($column['type']);

        if (str_starts_with($type, 'uuid')) {
            // Deterministic, valid, and distinct per (column, variant): md5 gives 32 hex digits, which is exactly
            // a UUID's payload. Version nibble forced to 4 so the value is a well-formed UUID rather than merely
            // hex-shaped -- TenantId::fromString() would refuse the latter.
            $hex = md5($column['name'] . ':' . $variant);

            return \sprintf(
                '%s-%s-4%s-8%s-%s',
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 13, 3),
                substr($hex, 17, 3),
                substr($hex, 20, 12),
            );
        }

        if (str_starts_with($type, 'numeric') || str_starts_with($type, 'double') || str_starts_with($type, 'real')) {
            return 'a' === $variant ? '1' : '2';
        }

        if (str_starts_with($type, 'smallint') || str_starts_with($type, 'integer') || str_starts_with($type, 'bigint')) {
            return 'a' === $variant ? '1' : '2';
        }

        if (str_starts_with($type, 'boolean')) {
            return 'a' === $variant ? 'true' : 'false';
        }

        if (str_starts_with($type, 'timestamp') || str_starts_with($type, 'date')) {
            return 'a' === $variant ? '2026-01-01 00:00:00+00' : '2026-01-02 00:00:00+00';
        }

        // character(n) is blank-padded and compares ignoring the padding, so a value must fit the declared width
        // or the insert fails on length rather than on anything this suite is testing.
        if (1 === preg_match('/^character\((\d+)\)$/', $type, $matches)) {
            return substr(str_pad('a' === $variant ? 'A' : 'B', (int) $matches[1], 'X'), 0, (int) $matches[1]);
        }

        return 'a' === $variant ? 'value-a' : 'value-b';
    }

    /**
     * @param array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{columns: list<string>, parent: string, parentColumns: list<string>}>} $relation
     * @param array<string, string> $row
     */
    private static function insertSql(array $relation, array $row): string
    {
        return \sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::qualifyQuoted($relation),
            implode(', ', array_map(self::quote(...), array_keys($row))),
            implode(', ', array_map(self::literal(...), array_values($row))),
        );
    }

    // ---------------------------------------------------------------------------------------------------------
    // PLUMBING
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Run one statement as the runtime role, bound to one tenant, inside a transaction that is always rolled back.
     *
     * Rolled back so the attacks cannot interfere with one another, and so a successful attack does not leave the
     * fixture mutated for the next case. The binding uses the PRODUCTION path — `bind()` on the real isolation
     * strategy — rather than a hand-rolled `set_config`, so a defect in binding shows up here too.
     *
     * @return array{ok: bool, affected: int, sqlstate: string, message: string}
     */
    private static function attempt(\PDO $connection, string $tenant, string $sql): array
    {
        $connection->beginTransaction();

        try {
            self::bindTo($connection, $tenant);
            $affected = $connection->exec($sql);

            return [
                'ok' => true,
                'affected' => false === $affected ? 0 : $affected,
                'sqlstate' => '00000',
                'message' => '',
            ];
        } catch (\PDOException $e) {
            return [
                'ok' => false,
                'affected' => 0,
                'sqlstate' => (string) ($e->errorInfo[0] ?? '?'),
                'message' => self::firstLineOf($e->getMessage()),
            ];
        } finally {
            $connection->rollBack();
        }
    }

    private static function boundQuery(\PDO $connection, string $tenant, string $sql): int
    {
        $connection->beginTransaction();

        try {
            self::bindTo($connection, $tenant);
            $statement = $connection->query($sql);

            self::assertNotFalse($statement, 'the bound query returned no statement');

            return (int) $statement->fetchColumn();
        } finally {
            $connection->rollBack();
        }
    }

    private static function bindTo(\PDO $connection, string $tenant): void
    {
        new PostgresRowLevelSecurityIsolation()->bind(
            $connection,
            InMemoryTenantContext::forTenant(TenantId::fromString($tenant)),
        );
    }

    /**
     * Whether a refusal came from the row-level security policy rather than from anything else.
     *
     * BOTH the SQLSTATE and the message, because they are not equivalent. [Verified 2026-08-01 against a real
     * policy: a policy refusal and an outright missing privilege BOTH raise `42501`, distinguished only by
     * `new row violates row-level security policy` versus `permission denied for table`.] So a table the runtime
     * role simply cannot write to would otherwise read as a table protected by a policy — a false clean on the
     * one axis where a false clean is a reportable breach.
     *
     * @param array{ok: bool, affected: int, sqlstate: string, message: string} $outcome
     */
    private static function isRowSecurityRefusal(array $outcome): bool
    {
        return '42501' === $outcome['sqlstate']
            && str_contains(strtolower($outcome['message']), 'row-level security policy');
    }

    /** A relation that can be written to. A view, materialized view or foreign table is read-attacked only. */
    private static function acceptsWrites(array $relation): bool
    {
        return \in_array($relation['kind'], ['r', 'p'], true);
    }

    private static function qualify(array $relation): string
    {
        return 'public' === $relation['schema']
            ? $relation['name']
            : $relation['schema'] . '.' . $relation['name'];
    }

    private static function qualifyQuoted(array $relation): string
    {
        return self::quote($relation['schema']) . '.' . self::quote($relation['name']);
    }

    /** Quoted so a mixed-case or keyword identifier cannot be silently case-folded — round 13's lesson. */
    private static function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private static function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private static function firstLineOf(string $message): string
    {
        $line = strtok($message, "\n");

        return false === $line ? $message : trim($line);
    }

    private static function runtimeConnection(): \PDO
    {
        return self::connectionTo(self::DATABASE, self::runtimeRole(), self::runtimePassword());
    }

    private static function admin(): \PDO
    {
        return self::$admin ??= self::connectionTo(
            self::DATABASE,
            self::superuserName(),
            self::superuserPassword(),
        );
    }
}
