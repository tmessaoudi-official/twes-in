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
 * **WHY THIS EXISTS, and why it SUPPLEMENTS `scripts/gates/schema-tenancy.php` rather than replacing any of it**
 * (developer
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
        // ADDED 2026-08-06 with `document_number_rendered_is_digits`, and it arrived exactly the way the paragraph
        // above predicts: `Version20260806180000` landed, the type-derived value for a `varchar` column was not
        // digits, and this suite went RED naming the table and PostgreSQL's own constraint name rather than quietly
        // leaving `document` unattacked. That is the whole design of this map working as intended.
        //
        // '1' and '2' MATCH what `scalarFor()` synthesises for the `number` bigint beside it, which is free and
        // deliberate: `rowFor()`'s docblock notes these rows may be domain-invalid (a draft carrying a number), so
        // agreement is not required — but a rendered string that disagreed with its own sequence would be a fixture
        // that looks like the corruption `InvoiceMapper::numberFrom()` refuses, and a reader would waste time on it.
        'number_rendered' => ['1', '2'],
    ];

    /**
     * The SQLSTATEs a uniqueness collision raises.
     *
     * `23505` is a unique violation; **`23P01` is an EXCLUDE constraint**, and checking only the first was a
     * round-23 finding on the very axis it was written to close — the exclusion-constraint case took GOAL 7's
     * "could not be attempted" branch, whose message also contains the word "uniqueness", so the assertion passed
     * on the wrong branch and the `23505` arm was left unpinned.
     */
    private const array COLLISION_SQLSTATES = ['23505', '23P01'];

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
     * Defence in depth ON TOP of the gate's `relforcerowsecurity` read, not a replacement for it — that axis is
     * live at `scripts/gates/schema-tenancy.php`, and it is the only half that can see a LIVE schema drift.
     * This one is stronger evidence about the migration's output: the flag being set and the flag WORKING are
     * different claims. Migrations connect as this role, so
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
     * Defence in depth ON TOP of the gate's role-attribute axis, which is LIVE and must stay so — round 24
     * reproduced a reachable owner running `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` while this probe reported
     * "reached, gained nothing", and `rolreplication` is observable by no attack at all because `pg_basebackup`
     * never traverses the query layer. So the gate is the sole detector for part of this property. What this adds
     * is the inheritance question the catalogue got wrong as a round-22 P0:
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
        // No `$relations[0]` any more: the escalation probes EVERY relation, because a role reached by SET ROLE may
        // hold a privilege on one table and nothing on another -- which is exactly the shape that hid the TRUNCATE.
        $runtime = self::runtimeConnection();
        // TWO TERMINAL CATEGORIES, and they used to be one plus a scratch variable. `$attempted` was pushed the
        // moment `SET ROLE` succeeded and then `array_pop`ped in the "gained nothing" branch, and since the only
        // other way out of the loop body is `self::fail()`, it was **always empty** by the time the assertion
        // below counted it — so `+ \count($attempted)` contributed nothing and the failure message's `escalated:`
        // line said `none` on every possible run. PHPStan found it as `Ternary operator condition is always
        // false` on the `?: 'none'` guarding that line, which is the giveaway: a fallback that always fires means
        // the value it replaces is always absent. Now the push happens where the outcome is KNOWN, nothing is
        // popped, and the two lists are genuinely disjoint — `$confined` is "reached it and it was worth
        // nothing", `$refused` is "could not reach it at all".
        $confined = [];
        $refused = [];

        foreach ($reachable as $role) {
            $runtime->beginTransaction();

            try {
                self::bindTo($runtime, self::TENANT_B);

                try {
                    $runtime->exec(\sprintf('SET ROLE %s', self::quote($role)));
                } catch (\PDOException $e) {
                    // REFUSED, which is the desired outcome and not an error in the test. `pg_has_role(...,
                    // 'MEMBER')` is true for a membership granted `WITH SET FALSE`, and PostgreSQL then still
                    // refuses `SET ROLE` -- the `twes_unsettable` and chain roles the provisioning script creates
                    // exist precisely to produce that shape. An assertion that treated this as a failure would
                    // make the suite red on a CORRECTLY provisioned cluster.
                    $refused[] = $role . ' (' . self::firstLineOf($e->getMessage()) . ')';

                    continue;
                }

                /*
                 * WHAT THE ESCALATED ROLE CAN DO, ON EVERY RELATION AND WITH MORE THAN ONE COMMAND.
                 *
                 * Round 23 filed this as a P0 with a reproduced erasure. The previous version asked a single
                 * question — can this role SELECT tenant A's rows from ONE relation — and banked a privilege error
                 * on it as a refusal. So `SET ROLE twes_truncator; TRUNCATE document;` erased both tenants' rows
                 * while this test reported "reached, then permission denied" and moved on. The provisioning script
                 * grants TRUNCATE to `twes_truncator` and grants that role to `twes` WITH INHERIT FALSE precisely
                 * because it is the one shape a privilege check resolved by inheritance cannot see — so GOAL 6,
                 * which runs on the plain runtime connection, cannot see it either. `SET ROLE` is the only path to
                 * it, and this was the only test that takes that path.
                 *
                 * A privilege error on one command no longer excuses the role: each is judged on its own, and the
                 * role counts as refused only when NOTHING it was asked to do succeeded.
                 */
                $gained = [];

                foreach ($relations as $target) {
                    $probes = [
                        'read' => \sprintf(
                            'SELECT count(*) FROM %s WHERE %s = %s',
                            self::qualifyQuoted($target),
                            self::quote(PostgresRowLevelSecurityIsolation::TENANT_COLUMN),
                            self::literal(self::TENANT_A),
                        ),
                    ];

                    if (self::acceptsWrites($target)) {
                        // Inside a transaction that is always rolled back, so an escalation that DOES succeed does
                        // not destroy the fixture for the remaining candidates.
                        // BOTH forms, for the reason GOAL 6 needs both: a plain TRUNCATE is refused on a table
                        // with inbound foreign keys, while CASCADE additionally requires the privilege on every
                        // referencing table. Probing only one form misses a grant on the other's blind spot -- and
                        // probing only CASCADE is what made this case's first fixture look clean.
                        $probes['TRUNCATE'] = \sprintf('TRUNCATE %s', self::qualifyQuoted($target));
                        $probes['TRUNCATE CASCADE'] = \sprintf('TRUNCATE %s CASCADE', self::qualifyQuoted($target));
                        $probes['DELETE'] = \sprintf(
                            'DELETE FROM %s WHERE %s = %s',
                            self::qualifyQuoted($target),
                            self::quote(PostgresRowLevelSecurityIsolation::TENANT_COLUMN),
                            self::literal(self::TENANT_A),
                        );
                    }

                    foreach ($probes as $what => $sql) {
                        $runtime->exec('SAVEPOINT escalation_probe');

                        try {
                            if ('read' === $what) {
                                $rows = (int) $runtime->query($sql)->fetchColumn();

                                if (0 !== $rows) {
                                    $gained[] = \sprintf('read %d of tenant A\'s rows from %s', $rows, self::qualify($target));
                                }
                            } else {
                                $affected = $runtime->exec($sql);

                                // A zero-row DELETE is the policy HOLDING, not a gain -- the rows were invisible.
                                // Round 24 reproduced the false positive: with the ordinary group-role grant
                                // pattern the escalated role can issue the statement, it affects nothing, and the
                                // suite called that a reproduced breach. TRUNCATE has no such test (any successful
                                // TRUNCATE IS the breach), so only the row-counting verbs are guarded.
                                if (str_starts_with($what, 'TRUNCATE') || (false !== $affected && 0 !== $affected)) {
                                    $gained[] = \sprintf(
                                        '%s on %s (%s rows)',
                                        $what,
                                        self::qualify($target),
                                        false === $affected ? 'unknown' : (string) $affected,
                                    );
                                }
                            }
                        } catch (\PDOException) {
                            // Refused for this one command. Not evidence about the others, which is the whole fix.
                        } finally {
                            $runtime->exec('ROLLBACK TO SAVEPOINT escalation_probe');
                        }
                    }
                }

                if ([] === $gained) {
                    $confined[] = $role;

                    continue;
                }

                self::fail(\sprintf(
                    "The runtime role reached \"%s\" with SET ROLE and then GAINED:\n  - %s\nA role reachable from "
                    . 'the application\'s role must be able to do nothing the application cannot. Note its own '
                    . 'catalogue row may look clean: rolsuper and rolbypassrls are not inherited, and a grant made '
                    . 'WITH INHERIT FALSE is invisible to any privilege check resolved by inheritance — which is '
                    . 'why this path exists and why GOAL 6 alone cannot see it.',
                    $role,
                    implode("\n  - ", $gained),
                ));
            } finally {
                $runtime->rollBack();
            }
        }

        // An empty candidate list is the DESIRED state rather than an anti-vacuity failure -- a runtime role that
        // can become nothing is what production wants. Asserted as a conjunction so the reader can tell the two
        // apart from the failure message: every candidate was either escalated-into-and-still-confined, or refused.
        self::assertSame(
            \count($reachable),
            \count($confined) + \count($refused),
            \sprintf(
                "Some reachable role was neither escalated into nor refused.\n  candidates: %s\n"
                . "  reached and confined: %s\n  refused: %s",
                implode(', ', $reachable) ?: 'none',
                implode(', ', $confined) ?: 'none',
                implode(', ', $refused) ?: 'none',
            ),
        );
    }

    // ---------------------------------------------------------------------------------------------------------
    // PROOFS THAT EACH ATTACK REALLY FIRES. Each case introduces a concrete defect and requires the ATTACK to
    // report it — or, for the two NEGATIVE cases, requires it NOT to.
    //
    // NOTE these are no longer "proofs that a deleted axis is covered". Every axis these cases exercise is LIVE
    // in `scripts/gates/schema-tenancy.php`: the key-shape axis came back at round 24 after two lenses
    // reproduced a cross-tenant oracle without it, and nothing else ever left. The cases remain valuable as
    // defence in depth and as proof this suite can fail, which is the thing a security suite most needs to
    // demonstrate about itself.
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
        // The SPECIFIC finding. `attackRelation()` also emits "could not be attempted" into the same array,
        // and that message contains the word "uniqueness" too -- so asserting on a substring of it, or merely
        // on the list being non-empty, is satisfied by the attack failing to ARRIVE. Round 23 filed that.
        self::assertStringContainsString('cannot be stored by the other', implode(' ', $findings));
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
        // An EXCLUDE violation is 23P01, not 23505. Before round 23 this case passed on the FALLBACK message
        // while the collision branch was never reached, so the arm it exists to pin was unpinned.
        self::assertStringContainsString('cannot be stored by the other', implode(' ', $findings));
        self::assertStringNotContainsString('could not be attempted', implode(' ', $findings));
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
        // The READ finding specifically -- a matview cannot be written to, so any other finding here would
        // mean the read attack never ran.
        self::assertStringContainsString('the runtime role READ', implode(' ', $findings));
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
        self::assertStringContainsString('the runtime role READ', implode(' ', $findings));
    }

    /**
     * A PARTIAL unique index omitting the tenant — round 23's reproduced existence oracle, and the case that
     * forced GOAL 7 to probe in BOTH directions.
     *
     * The blind spot was created by a decision that looked purely defensive: the two tenants are given DIFFERENT
     * values in every non-tenant column, so the probe cannot collide with the attacking tenant's own row. A partial
     * index's predicate is evaluated on exactly those differing columns — so an index predicated on
     * `state = 'issued'` never saw a probe row carrying variant `'a'`'s `state = 'draft'`, and the suite certified a
     * schema where tenant B could not issue a number tenant A had issued.
     *
     * Not a contrived index. `Version20260801120000.php` already reasons in this shape — *"NULL numbers do not
     * collide … only issued documents are constrained"* — and `UNIQUE (number) WHERE state = 'issued'` is the
     * natural next step for a gapless legal number. Probing A-under-B and B-under-A covers a predicate selecting
     * either value, which closes the class rather than this one example.
     */
    public function testTheUniqueProbeCatchesAPartialIndexOmittingTheTenant(): void
    {
        $findings = $this->attackDefectiveRelation(
            'partial_unique',
            [
                'CREATE TABLE partial_unique (company_id uuid NOT NULL, id uuid NOT NULL, state text NOT NULL,'
                . ' number bigint NOT NULL, PRIMARY KEY (company_id, id))',
                "ALTER TABLE partial_unique ADD CONSTRAINT partial_unique_state_known"
                . " CHECK (state IN ('draft', 'issued'))",
                "CREATE UNIQUE INDEX partial_unique_defect ON partial_unique (number) WHERE state = 'issued'",
            ],
        );

        self::assertStringContainsString(
            'cannot be stored by the other',
            implode(' ', $findings),
            'A partial unique index omitting the tenant went undetected. The probe must run in BOTH directions: '
            . "the predicate selects on exactly the columns the two tenants' rows differ in.",
        );
    }

    /**
     * A composite foreign key that is composite in the WRONG columns — round 23's reproduced cross-tenant
     * `ON DELETE CASCADE` delete, and the case that fixed GOAL 8.
     *
     * `payment(company_id, id, invoice_company_id, invoice_id)` with `FOREIGN KEY (invoice_company_id, invoice_id)`
     * is composite, so a key-shape check looking for "two columns" is satisfied — and nothing constrains
     * `invoice_company_id = company_id`. Tenant B inserts a payment against tenant A's invoice; tenant A deletes
     * its own invoice; tenant B's row cascades away.
     *
     * GOAL 8 missed it for a reason worth keeping: it built tenant B's own row and overwrote only the FK columns,
     * so the row collided with B's seeded row on the primary key and died on `23505` BEFORE the foreign key was
     * consulted — and the old code banked any failure as a refusal.
     */
    public function testGoalEightCatchesAForeignKeyThatIsCompositeInTheWrongColumns(): void
    {
        $findings = $this->attackDefectiveRelation(
            'wrong_pair_payment',
            [
                'CREATE TABLE wrong_pair_invoice (company_id uuid NOT NULL, id uuid NOT NULL,'
                . ' PRIMARY KEY (company_id, id))',
                'CREATE TABLE wrong_pair_payment (company_id uuid NOT NULL, id uuid NOT NULL,'
                . ' invoice_company_id uuid NOT NULL, invoice_id uuid NOT NULL, PRIMARY KEY (company_id, id),'
                . ' CONSTRAINT wrong_pair_defect FOREIGN KEY (invoice_company_id, invoice_id)'
                . ' REFERENCES wrong_pair_invoice (company_id, id) ON DELETE CASCADE)',
            ],
            seedInto: 'wrong_pair_invoice, wrong_pair_payment',
        );

        self::assertStringContainsString(
            'REFERENCING',
            implode(' ', $findings),
            'A foreign key composite in the wrong pair of columns went undetected, which is a cross-tenant '
            . 'ON DELETE CASCADE delete.',
        );
    }

    /**
     * `TRUNCATE` held by a role the runtime role can `SET ROLE` to but does NOT inherit from — round 23's
     * reproduced erasure of every tenant's rows.
     *
     * The one shape a privilege check resolved by inheritance cannot see. `provision-test-database.sh` grants
     * `twes_truncator` to the runtime role `WITH INHERIT FALSE` precisely to make it testable, and GOAL 6 cannot
     * see it because GOAL 6 runs on the plain runtime connection. `SET ROLE` is the only path, and before this the
     * escalation test took that path and then asked only whether it could `SELECT`.
     *
     * Uses the migrated `document` table rather than a fixture of its own, because the whole point is that the
     * privilege is held somewhere the runtime connection's own ACL does not show it.
     */
    public function testTheEscalationProbeCatchesTruncateReachableOnlyBySetRole(): void
    {
        $truncator = getenv('TWES_TEST_DB_TRUNCATOR_ROLE') ?: 'twes_truncator';
        $admin = self::admin();
        $relations = self::discoverTenantRelations($admin);
        $this->seedBothTenants($relations);

        // `document_line`, a LEAF. TRUNCATE on `document` alone erases nothing — the plain form is refused by the
        // inbound foreign keys and CASCADE needs the privilege on the children — so granting it there would have
        // produced a fixture that could not express the danger. Truncating the lines of every tenant is damage
        // enough, and it is the realistic shape of an over-broad grant.
        $admin->exec(\sprintf('GRANT TRUNCATE ON document_line TO %s', self::quote($truncator)));

        try {
            $failure = null;

            try {
                $this->testTheRuntimeRoleCannotEscalateToARoleThatBypassesPolicies();
            } catch (\PHPUnit\Framework\AssertionFailedError $caught) {
                $failure = $caught;
            }

            self::assertNotNull(
                $failure,
                \sprintf(
                    'TRUNCATE on document_line granted to "%s" — reachable from the runtime role by SET ROLE but '
                    . 'NOT inherited — '
                    . 'went undetected. Two statements erase every tenant\'s rows, and no privilege check resolved '
                    . 'by inheritance can see the grant.',
                    $truncator,
                ),
            );
            self::assertStringContainsString('TRUNCATE on document_line', $failure->getMessage());
        } finally {
            $admin->exec(\sprintf('REVOKE TRUNCATE ON document_line FROM %s', self::quote($truncator)));
        }
    }

    /**
     * GOAL 8's SQLSTATE guard, pinned — a cross-tenant reference refused for the WRONG reason must be reported as
     * unprobed rather than banked as evidence.
     *
     * This is the guard whose absence let round 23's reproduced cross-tenant CASCADE delete through, and the
     * row-construction fix alone does not pin it: with the row built correctly the breach case now SUCCEEDS, so the
     * guard is never consulted there. It needs a relation whose GOAL 8 insert fails for something other than
     * `23503`. A global `UNIQUE (code)` does it: the probe carries tenant A's `code`, so the unique index refuses it
     * before the foreign-key trigger fires, and without the guard that reads as "the foreign key refused a
     * cross-tenant reference" about a reference never tested.
     */
    public function testGoalEightReportsAReferenceRefusedForAnUnrelatedReasonAsUnprobed(): void
    {
        $findings = $this->attackDefectiveRelation(
            'oracle_child',
            [
                'CREATE TABLE oracle_parent (company_id uuid NOT NULL, id uuid NOT NULL,'
                . ' PRIMARY KEY (company_id, id))',
                'CREATE TABLE oracle_child (company_id uuid NOT NULL, parent_id uuid NOT NULL, code text NOT NULL,'
                . ' PRIMARY KEY (company_id, parent_id),'
                . ' CONSTRAINT oracle_child_fk FOREIGN KEY (company_id, parent_id)'
                . ' REFERENCES oracle_parent (company_id, id) ON DELETE CASCADE)',
                'CREATE UNIQUE INDEX oracle_child_global_code ON oracle_child (code)',
            ],
            seedInto: 'oracle_parent, oracle_child',
        );

        self::assertStringContainsString(
            'rather than by the foreign key, so the reference was never tested',
            implode(' ', $findings),
            'GOAL 8 must report a reference refused for an unrelated reason as UNPROBED. Banking it is what let a '
            . 'cross-tenant ON DELETE CASCADE delete through round 22.',
        );
    }

    /**
     * GOALS 3 and 5 must report an UNATTEMPTABLE cross-tenant write as unprobed, not bank it as a refusal.
     *
     * On a correctly policed relation tenant A's row is INVISIBLE to tenant B, so a cross-tenant UPDATE or DELETE
     * reports zero rows affected rather than raising. An error means something else refused it — most often a
     * missing privilege, which raises the same `42501` a policy does. Withholding UPDATE and DELETE is the smallest
     * way to produce that state, and before round 23 both goals counted it as evidence of isolation while the
     * positive control, which only ever ran a `SELECT`, could not tell.
     */
    public function testCrossTenantWritesThatCannotBeAttemptedAreReportedRatherThanBanked(): void
    {
        $findings = $this->attackDefectiveRelation(
            'read_only_relation',
            ['CREATE TABLE read_only_relation (company_id uuid NOT NULL, code text NOT NULL)'],
            grants: 'SELECT, INSERT',
        );

        $report = implode(' ', $findings);

        self::assertStringContainsString('the cross-tenant UPDATE could not be attempted', $report);
        self::assertStringContainsString('the cross-tenant DELETE could not be attempted', $report);
    }

    /**
     * A CORRECT foreign key beside a DEFECTIVE one — round 24's P0, where the correct key answered for both.
     *
     * GOAL 8 built its probe row once, outside the per-FK loop, so the identical insert was retried for every
     * foreign key and the strictest constraint refused first. A reviewer put `fk_correct` (tenant-composite) beside
     * a key tied to a second pair of columns, watched `fk_correct`'s `23503` be banked as proof about the other,
     * then rode the other to a cross-tenant `ON DELETE CASCADE` delete. Latent on today's schema only because
     * `document_line` has exactly one foreign key — Wave 2's payment table will have two.
     *
     * The fix has two halves and this case needs both: the row is built per foreign key with the OTHER keys
     * neutralised, and the refusal must name THIS constraint rather than merely carry SQLSTATE `23503`.
     */
    public function testGoalEightIsNotMaskedByACorrectSiblingForeignKey(): void
    {
        $findings = $this->attackDefectiveRelation(
            'sibling_payment',
            [
                'CREATE TABLE sibling_doc (company_id uuid NOT NULL, id uuid NOT NULL,'
                . ' PRIMARY KEY (company_id, id))',
                'CREATE TABLE sibling_invoice (company_id uuid NOT NULL, id uuid NOT NULL,'
                . ' PRIMARY KEY (company_id, id))',
                'CREATE TABLE sibling_payment (company_id uuid NOT NULL, id uuid NOT NULL,'
                . ' doc_id uuid NOT NULL, inv_company_id uuid NOT NULL, inv_id uuid NOT NULL,'
                . ' PRIMARY KEY (company_id, id),'
                // CORRECT: tenant-composite, and it will refuse the probe row on its own.
                . ' CONSTRAINT sibling_fk_correct FOREIGN KEY (company_id, doc_id)'
                . '   REFERENCES sibling_doc (company_id, id),'
                // DEFECTIVE: composite, but in a second pair of columns with nothing tying it to the row's tenant.
                . ' CONSTRAINT sibling_fk_defect FOREIGN KEY (inv_company_id, inv_id)'
                . '   REFERENCES sibling_invoice (company_id, id) ON DELETE CASCADE)',
            ],
            seedInto: 'sibling_doc, sibling_invoice, sibling_payment',
        );

        self::assertStringContainsString(
            'sibling_fk_defect',
            implode(' ', $findings),
            "The defective foreign key must be named. If the correct sibling's refusal is banked instead, a "
            . 'cross-tenant ON DELETE CASCADE delete passes every attack — reproduced in round 24.',
        );
    }

    /**
     * A reachable role that can ISSUE a `DELETE` but whose rows the policy hides must NOT be reported as a breach.
     *
     * Round 24 reproduced this false positive: the escalation probe appended to `$gained` on any successful `exec`,
     * so with the ordinary grant pattern — privileges on a group role, the group granted to the runtime role — the
     * escalated role issues the DELETE, it affects ZERO rows because the policy hid tenant A's rows, and the suite
     * called that a reproduced breach. This repository prices a false finding as badly as a false clean, and the
     * fix a maintainer would reach for when it fires is deleting the probe, which reopens the round-23 P0 it exists
     * to close.
     *
     * `TRUNCATE` is deliberately still unguarded by row count: any successful TRUNCATE IS the breach.
     */
    public function testTheEscalationProbeDoesNotReportADeleteThePolicyAlreadyRefused(): void
    {
        $truncator = getenv('TWES_TEST_DB_TRUNCATOR_ROLE') ?: 'twes_truncator';
        $admin = self::admin();
        $this->seedBothTenants(self::discoverTenantRelations($admin));

        // SELECT and DELETE but NOT TRUNCATE: the escalated role can issue both statements, and the policy is what
        // stops them reaching another tenant's rows.
        $admin->exec(\sprintf('GRANT SELECT, DELETE ON document TO %s', self::quote($truncator)));

        try {
            $this->testTheRuntimeRoleCannotEscalateToARoleThatBypassesPolicies();
        } finally {
            $admin->exec(\sprintf('REVOKE SELECT, DELETE ON document FROM %s', self::quote($truncator)));
        }
    }

    /**
     * A CORRECT 1:1 child relation must produce NO finding — round 24's reproduced false positive.
     *
     * `PRIMARY KEY (company_id, document_id)` with a tenant-composite foreign key and nothing else is a perfectly
     * isolated schema. GOAL 7's probe has to re-point the parent reference at the ATTACKING tenant's parent to
     * satisfy the foreign key, and on this shape that reproduces the attacking tenant's own key exactly — so the
     * probe collided with its own row and reported a cross-tenant oracle on a correct schema.
     *
     * `document_line` and `document_charge` escape only because `position` differs between the two variants, which
     * is luck rather than design — hence this case. The fix restricts GOAL 7 to relations with no foreign key, and
     * `scripts/gates/schema-tenancy.php` owns the axis authoritatively for the rest.
     */
    public function testACorrectOneToOneChildRelationProducesNoFinding(): void
    {
        $findings = $this->attackDefectiveRelation(
            'einvoice_payload',
            [
                'CREATE TABLE einvoice_doc (company_id uuid NOT NULL, id uuid NOT NULL,'
                . ' PRIMARY KEY (company_id, id))',
                'CREATE TABLE einvoice_payload (company_id uuid NOT NULL, document_id uuid NOT NULL,'
                . ' PRIMARY KEY (company_id, document_id),'
                . ' CONSTRAINT einvoice_payload_fk FOREIGN KEY (company_id, document_id)'
                . '   REFERENCES einvoice_doc (company_id, id) ON DELETE CASCADE)',
            ],
            seedInto: 'einvoice_doc, einvoice_payload',
        );

        self::assertSame(
            [],
            $findings,
            "A correct 1:1 child relation must produce NO finding. A false positive here is as damaging as a false "
            . "clean: it is the red a maintainer learns to dismiss.\n  - " . implode("\n  - ", $findings),
        );
    }

    // ---------------------------------------------------------------------------------------------------------
    // THE ATTACKS
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Every attacker goal, against one relation.
     *
     * `fks[].name` WAS MISSING FROM THIS ONE DOCBLOCK and from no other. Round 24 added the constraint name to
     * the shape so that GOAL 8's finding could say WHICH foreign key accepted a cross-tenant reference — a
     * sibling key raising the same `23503` is not evidence about the defective one — and updated eight of the
     * nine declarations. This method is the one that READS `$fk['name']`, twice, so the stale copy was the worst
     * possible place for it: an `Undefined array key` there turns a reproduced cross-tenant breach into a
     * confusing PHP error. Nothing could see it until PHPStan ran; it reported both reads plus six calls handing
     * `rowFor()` and `insertSql()` a value that did not satisfy their contract.
     *
     * @param array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string,
     *              nullable: bool}>, fks: list<array{name: string, columns: list<string>, parent: string,
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
        $insert = self::attemptAsTenant($runtime, self::TENANT_B, self::insertSql($relation, $crossTenantRow));

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
        $update = self::attemptAsTenant($runtime, self::TENANT_B, \sprintf(
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
        } elseif (!$update['ok']) {
            // A REFUSAL here is not evidence, and banking it was a round-23 finding. On a correctly policed
            // relation tenant A's row is simply INVISIBLE to tenant B, so this reports `ok` with zero rows
            // affected. An error instead means something else refused it -- most likely a missing UPDATE
            // privilege, which raises the same 42501 a policy does -- and a relation the runtime role cannot
            // update at all satisfies this goal for a reason that says nothing about isolation.
            $findings[] = \sprintf(
                '%s: the cross-tenant UPDATE could not be attempted — it failed with %s ("%s") rather than '
                . 'reporting zero rows affected, so this relation went unprobed for cross-tenant modification.',
                $name,
                $update['sqlstate'],
                $update['message'],
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
        $reparent = self::attemptAsTenant($runtime, self::TENANT_A, \sprintf(
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
        $delete = self::attemptAsTenant($runtime, self::TENANT_B, \sprintf(
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
        } elseif (!$delete['ok']) {
            // Same discrimination as GOAL 3, for the same round-23 reason: an error is not the refusal this goal
            // is looking for. Zero rows affected is.
            $findings[] = \sprintf(
                '%s: the cross-tenant DELETE could not be attempted — it failed with %s ("%s") rather than '
                . 'reporting zero rows affected, so this relation went unprobed for cross-tenant deletion.',
                $name,
                $delete['sqlstate'],
                $delete['message'],
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
        $truncate = self::attemptAsTenant($runtime, self::TENANT_B, \sprintf('TRUNCATE %s', $table));

        if (!$truncate['ok'] && '42501' !== $truncate['sqlstate']) {
            $truncate = self::attemptAsTenant($runtime, self::TENANT_B, \sprintf('TRUNCATE %s CASCADE', $table));
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
        //
        // RUN IN BOTH DIRECTIONS, and round 23 proved that necessary rather than thorough. The probe re-presents
        // one tenant's column values under the other, and the two tenants deliberately differ in EVERY non-tenant
        // column so the probe cannot collide with the attacking tenant's own row. A PARTIAL unique index's
        // predicate is evaluated on exactly those differing columns — so `UNIQUE (number) WHERE state = 'issued'`
        // never sees a probe row carrying variant 'a''s `state = 'draft'`, and a reviewer reproduced the resulting
        // cross-tenant existence oracle on a schema this suite certified clean. That index is not contrived: it is
        // the natural next step for a gapless legal number, and `Version20260801120000.php` already reasons in
        // that shape for NULL numbers.
        //
        // Probing A-under-B and B-under-A covers a predicate selecting EITHER value, which is the smallest fix
        // that closes the class rather than the one example.
        //
        // RESTRICTED TO RELATIONS WITH NO FOREIGN KEY, and this is a stated limit rather than a silent one. On a
        // child relation the probe must re-point the parent reference at the ATTACKING tenant's parent to satisfy
        // the foreign key -- and on a 1:1 child whose key is exactly `(tenant, parent_ref)` that reproduces the
        // attacking tenant's OWN key, so the probe collides with its own row and reports a breach on a CORRECT
        // schema. Round 24 reproduced that false positive, and this repository prices a false finding as badly as a
        // false clean: the next red gets dismissed.
        //
        // Nothing is lost by the restriction, because `scripts/gates/schema-tenancy.php` owns this axis
        // authoritatively again -- it reads key columns from the catalogue, so a predicate cannot hide a key from it
        // and a child relation is no harder than a root one. This probe is defence in depth on root relations, which
        // is where a tenant-scoped counter or a human-facing document number lives.
        foreach ([] === $relation['fks']
            ? [['a', 'b', self::TENANT_B], ['b', 'a', self::TENANT_A]]
            : [] as [$values, $parent, $under]) {
            $probe = self::rowFor($relation, $under, $values, parentVariant: $parent);
            $collision = self::attemptAsTenant($runtime, $under, self::insertSql($relation, $probe));
            $direction = \sprintf('variant %s under tenant %s', $values, self::TENANT_A === $under ? 'A' : 'B');

            // BOTH collision codes. `23505` is a unique violation; an EXCLUDE constraint raises **`23P01`**, and
            // checking only the first meant the case written to prove R22-2 closed took the fallback branch below
            // and reported "could not be attempted" about a genuinely defective schema -- the wrong-bound message
            // shape CLAUDE.md records for `document.quantity_too_large`, on the axis it was closing.
            if (!$collision['ok'] && \in_array($collision['sqlstate'], self::COLLISION_SQLSTATES, true)) {
                $findings[] = \sprintf(
                    '%s: a value one tenant already uses cannot be stored by the other (%s) — "%s". Some '
                    . 'uniqueness mechanism on this relation does not include "%s", and uniqueness checks run with '
                    . 'row-level security BYPASSED, so it is enforced across EVERY tenant. That is a cross-tenant '
                    . "existence oracle and a denial of service on another tenant's numbering.",
                    $name,
                    $direction,
                    $collision['message'],
                    PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
                );
            } elseif (!$collision['ok']) {
                $findings[] = \sprintf(
                    '%s: the uniqueness probe could not be attempted (%s) — the insert failed with %s ("%s") for '
                    . 'an unrelated reason, so this relation went unprobed in that direction. A silent skip here '
                    . 'is how an INCLUDE-column index stayed invisible for twenty rounds.',
                    $name,
                    $direction,
                    $collision['sqlstate'],
                    $collision['message'],
                );
            } else {
                ++$refusals;
            }
        }

        // GOAL 8: REFERENCE another tenant's row. Tenant B's own row, but with its parent reference left pointing
        // at tenant A's parent. A composite foreign key refuses this with 23503; a single-column one accepts it,
        // and `ON DELETE CASCADE` then reaches across the boundary to delete.
        //
        // THE ROW IS TENANT A'S OWN, WITH ONLY THE TENANT COLUMN FLIPPED TO B -- and getting that wrong was a
        // round-23 P0 with a reproduced cross-tenant `ON DELETE CASCADE` delete behind it. The previous version
        // built tenant B's row (variant 'b') and overwrote only the non-tenant FK columns. `rowFor()` is
        // deterministic, so that row was byte-identical to B's SEEDED row except for those columns -- meaning any
        // key column that is not an FK column duplicated B's own row and the insert died on `23505` before the
        // foreign key was ever consulted. A reviewer built `payment(company_id, id, inv_company_id, inv_id)` with a
        // composite FK tied to the second pair, inserted a payment under tenant B referencing tenant A's invoice,
        // had tenant A delete its own invoice, and watched B's row cascade away -- with every attack reporting
        // clean.
        //
        // Taking A's exact row and changing only the tenant fixes both halves at once: it cannot collide with B's
        // row (every other column is A's), and it leaves the FK pointing exactly where A's row pointed. If the FK
        // includes the tenant column the reference is now dangling and PostgreSQL refuses with `23503`; if it does
        // not, the reference still resolves to tenant A's parent and the insert SUCCEEDS -- which is the breach.
        foreach ($relation['fks'] as $fk) {
            // PER FOREIGN KEY, and the refusal must name THIS constraint. Round 24 filed both halves: the row was
            // built once outside this loop, so the identical insert was retried for every FK and the STRICTEST
            // constraint answered for all of them -- a reviewer put a correct FK beside a defective one and watched
            // the correct one's `23503` be banked as proof about the defective one, then rode the defective one to a
            // cross-tenant ON DELETE CASCADE delete. Only this relation's OTHER foreign keys are neutralised, by
            // pointing them at the attacking tenant's own parents, so the one under test is the only thing that can
            // refuse.
            $reference = self::rowFor($relation, self::TENANT_A, 'a');
            $reference[PostgresRowLevelSecurityIsolation::TENANT_COLUMN] = self::TENANT_B;

            foreach ($relation['fks'] as $other) {
                if ($other['parent'] === $fk['parent'] && $other['columns'] === $fk['columns']) {
                    continue;
                }

                $ownParent = self::rowFor(self::relationNamed($other['parent']), self::TENANT_B, 'b');

                foreach ($other['columns'] as $index => $local) {
                    if (isset($other['parentColumns'][$index], $ownParent[$other['parentColumns'][$index]])) {
                        $reference[$local] = $ownParent[$other['parentColumns'][$index]];
                    }
                }
            }

            $crossReference = self::attemptAsTenant($runtime, self::TENANT_B, self::insertSql($relation, $reference));

            if ($crossReference['ok']) {
                // The CONSTRAINT NAME, not just the columns. With one foreign key per relation the columns were
                // enough; with two, a reader needs to know which constraint to change -- and a case asserting on
                // this finding cannot otherwise tell the sibling keys apart.
                $findings[] = \sprintf(
                    '%s: tenant B inserted a row REFERENCING tenant A\'s row in %s through "%s" (%s). Make the '
                    . 'foreign key composite on both sides AND tie it to this row\'s own tenant — a key that is '
                    . 'composite in a DIFFERENT pair of columns satisfies a shape check while still crossing the '
                    . 'boundary, and ON DELETE CASCADE then deletes across it.',
                    $name,
                    $fk['parent'],
                    $fk['name'],
                    implode(', ', $fk['columns']),
                );
                // NAMED, not merely `23503`. A sibling foreign key raising the same SQLSTATE is not evidence
                // about this one -- round 24's reproduction held the string `violates foreign key constraint
                // "fk_correct"` while the code attributed that refusal to the defective key.
            } elseif ('23503' !== $crossReference['sqlstate']
                || !str_contains($crossReference['message'], '"' . $fk['name'] . '"')) {
                // REQUIRE the foreign-key violation. This was the only goal accepting any failure as evidence,
                // which is precisely how the collision above hid a real breach -- and the plan's R22-7 closure
                // claimed this code "requires 23503" when it did not. Anything else means the reference was never
                // actually tested.
                $findings[] = \sprintf(
                    '%s: the cross-tenant reference through "%s" was refused by %s ("%s") rather than by the '
                    . 'foreign key, so the reference was never tested. A key collision here means the probe row '
                    . "duplicated the attacking tenant's own row before the foreign key was consulted.",
                    $name,
                    implode(', ', $fk['columns']),
                    $crossReference['sqlstate'],
                    $crossReference['message'],
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
        string $grants = 'SELECT, INSERT, UPDATE, DELETE',
    ): array {
        $admin = self::admin();
        $targets = array_map(trim(...), explode(',', $seedInto ?? $name));
        $created = [];

        foreach ($ddl as $statement) {
            $admin->exec($statement);

            if (1 === preg_match('/^CREATE (TABLE|MATERIALIZED VIEW|VIEW) (\w+)/', $statement, $m)) {
                $created[] = [$m[1], $m[2]];
            }
        }

        try {
            foreach ($targets as $target) {
                if ($policed) {
                    foreach (PostgresRowLevelSecurityIsolation::policySqlFor($target) as $statement) {
                        $admin->exec($statement);
                    }
                }

                $admin->exec(\sprintf('ALTER TABLE %s OWNER TO %s', $target, self::ownerRole()));
            }

            foreach ($created as [$kind, $relation]) {
                // A view or materialized view is read-attacked only, so SELECT is the whole requirement. Writing
                // to one is not an attacker goal this suite claims to cover.
                $admin->exec(\sprintf(
                    'GRANT %s ON %s TO %s',
                    'TABLE' === $kind ? $grants : 'SELECT',
                    $relation,
                    self::runtimeRole(),
                ));
            }

            foreach ($extraGrants as $statement) {
                $admin->exec($statement);
            }

            // Discover FIRST, then seed through the same synthesiser the attacks use -- see the docblock. Seeded in
            // DISCOVERY order, which `parentsFirst()` has already made parent-before-child, so a fixture with a
            // foreign key between two of its own tables works without the caller ordering it.
            self::$relations = null;
            $this->seedBothTenants(array_values(array_filter(
                self::discoverTenantRelations($admin),
                static fn(array $relation): bool => \in_array($relation['name'], $targets, true),
            )));

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

    /** @var ?list<array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{name: string, columns: list<string>, parent: string, parentColumns: list<string>}>}> */
    private static ?array $relations = null;

    /**
     * Every relation carrying the tenant column, in an order where a parent precedes its children.
     *
     * The relkind set matches the schema gate's on purpose: an ordinary table, a partitioned one, a view, a
     * materialized view and a foreign table can all hold tenant data, and the last three are precisely the ones a
     * catalogue check keeps getting wrong.
     *
     * @return list<array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{name: string, columns: list<string>, parent: string, parentColumns: list<string>}>}>
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
            . " (SELECT coalesce(json_agg(json_build_object('name', con.conname, 'columns', ("
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
            /** @var list<array{name: string, columns: list<string>, parent: string, parentColumns: list<string>}> $fks */
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
     * @param list<array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{name: string, columns: list<string>, parent: string, parentColumns: list<string>}>}> $relations
     *
     * @return list<array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{name: string, columns: list<string>, parent: string, parentColumns: list<string>}>}>
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
     * @return array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{name: string, columns: list<string>, parent: string, parentColumns: list<string>}>}
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
     * @param list<array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{name: string, columns: list<string>, parent: string, parentColumns: list<string>}>}> $relations
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
                    // THE MESSAGE NAMES THE LIKELY CAUSES BY SQLSTATE rather than asserting one. It used to state
                    // flatly that a CHECK-constrained column needed a COLUMN_VALUES entry, which sent a reader
                    // hunting for the wrong fix whenever the real cause was something else — a `DEFERRABLE` unique
                    // constraint, for instance, makes the `ON CONFLICT` arbiter itself illegal with `55000`, and
                    // that has nothing to do with column values. Round 23 filed the misdiagnosis; the
                    // wrong-bound-message shape CLAUDE.md records for `document.quantity_too_large`.
                    self::fail(\sprintf(
                        "Could not seed %s for tenant %s: %s\nThe synthesised row was: %s\n%s\nThis FAILS rather "
                        . 'than skipping on purpose — a relation that cannot be seeded is a relation that goes '
                        . 'unattacked, and an unattacked relation passes every assertion in this suite.',
                        self::qualify($relation),
                        $variant,
                        $e->getMessage(),
                        json_encode($row, \JSON_THROW_ON_ERROR),
                        match ((string) ($e->errorInfo[0] ?? '')) {
                            '23514' => 'A CHECK constraint refused a synthesised value: add an entry to '
                                . 'self::COLUMN_VALUES, keyed by column NAME, carrying two legal values.',
                            '55000' => 'The ON CONFLICT arbiter is illegal here — a DEFERRABLE unique or EXCLUDE '
                                . 'constraint cannot arbitrate. Seeding needs a different conflict strategy for '
                                . 'this relation; it is not a COLUMN_VALUES problem.',
                            '22P02', '22003' => 'A synthesised value does not fit the column type. Extend '
                                . 'self::scalarFor() for that type rather than widening COLUMN_VALUES.',
                            '23503' => 'A foreign key was not satisfied, so the parent was not seeded first. '
                                . 'Check parentsFirst() ordered this relation after its parent.',
                            default => 'Neither a CHECK constraint nor a type mismatch — read the SQLSTATE above '
                                . 'before assuming this is a COLUMN_VALUES problem.',
                        },
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
     * @param array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{name: string, columns: list<string>, parent: string, parentColumns: list<string>}>} $relation
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
     * @param array{schema: string, name: string, kind: string, columns: list<array{name: string, type: string, nullable: bool}>, fks: list<array{name: string, columns: list<string>, parent: string, parentColumns: list<string>}>} $relation
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
    private static function attemptAsTenant(\PDO $connection, string $tenant, string $sql): array
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

    /**
     * A relation that can be written to. A view, materialized view or foreign table is read-attacked only.
     *
     * The three helpers here take a NARROW shape rather than the full relation array — `array{kind: string}`
     * accepts any array carrying that key, so every caller still type-checks, and the signature then says which
     * of the five keys the helper actually reads. All three had a bare `array` until PHPStan asked.
     *
     * @param array{kind: string} $relation
     */
    private static function acceptsWrites(array $relation): bool
    {
        return \in_array($relation['kind'], ['r', 'p'], true);
    }

    /** @param array{schema: string, name: string} $relation */
    private static function qualify(array $relation): string
    {
        return 'public' === $relation['schema']
            ? $relation['name']
            : $relation['schema'] . '.' . $relation['name'];
    }

    /** @param array{schema: string, name: string} $relation */
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
