<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * SCHEMA TENANCY: the two things about a migrated schema that no ATTACK can observe.
 *
 * **THIS GATE WAS DELIBERATELY MADE SMALLER** (developer ruling, 2026-08-01, recorded in
 * `docs/plans/build-waves.plan.md`). It used to assert nine properties by reading catalogue metadata. Certification
 * round 22 produced SIX P0s in those checks while the schema they guard survived every attack both security lenses
 * could build -- every confirmed breach was in the checker, none in the thing checked. The unifying diagnosis: each
 * P0 came from **inferring a property from a description** instead of **observing the thing itself**. `indkey` vs
 * `indnkeyatts`; `contype` missing `'x'`; view-owner semantics; `pg_roles`' own row versus membership;
 * `text::regclass` versus the oid already in hand.
 *
 * So enumerating implementation SHAPES was abandoned -- it is unbounded, and PostgreSQL keeps adding to it -- in
 * favour of enumerating attacker GOALS, which is not.
 * **`api/tests/Integration/Tenancy/BehaviouralIsolationTest.php` is now the authority** on whether tenant data is
 * isolated: it seeds two tenants and, as the restricted runtime role, attempts to read, write, modify, re-parent,
 * delete, TRUNCATE, escalate into and probe every relation this gate's discovery finds. Every one of those attacks
 * is proven load-bearing by its own mutant. Four of round 22's six P0s stopped existing rather than being patched,
 * and a probe catches `EXCLUDE`, `INCLUDE` and whatever PostgreSQL 19 adds without naming any of them.
 *
 * WHAT REMAINS HERE, and why each one genuinely cannot be attacked:
 *
 *   1. **The tenant column is NOT NULL.** Under the canonical predicate a NULL tenant matches NOBODY, so such a row
 *      is invisible to every tenant including the one that wrote it. That is data loss wearing the appearance of
 *      isolation, and it is the one failure mode no cross-tenant read would ever reveal -- an attacker looking for
 *      another tenant's rows and a legitimate owner looking for its own both see nothing, which is exactly what a
 *      correctly isolated table looks like from outside.
 *
 *   2. **The runtime role does not OWN any relation.** `FORCE` stops an owner *skipping* policies, not *removing*
 *      them: an owner can `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` in one statement. A probe cannot see this,
 *      because the dangerous thing has not happened yet -- the schema behaves perfectly until someone runs that
 *      statement. **Deliberately NOT scoped to tenant-owned relations**, for the same reason: a table with no
 *      tenant column leaks nothing itself, but its owner PROVES which role migrations run as, so the next
 *      tenant-owned table they create will be owned by the runtime role too. That is not hypothetical --
 *      `doctrine_migration_versions` in the local `twes_in` database was exactly this on 2026-08-01. A precursor to
 *      a P0 is worth refusing while it is still only a precursor.
 *
 * Plus DISCOVERY itself, which is the input the behavioural suite consumes: which relations hold tenant data, and a
 * REFUSAL of any relation it cannot classify. A table carrying `tenant_id` or `org_id` but no `company_id` is
 * neither obviously tenant-owned nor obviously not, so it is refused with the two ways to resolve it. Skipping
 * would be the shape `CLAUDE.md` § Gotchas records four times over: a control that silently does not run is worse
 * than one openly owed.
 *
 * WHAT WAS DELETED, listed so nobody re-adds it believing it was an oversight. Every item is now proven covered by
 * a mutant-killed attack in the behavioural suite, and each was a source of false verdicts here:
 *   - composite-key shapes (PRIMARY KEY / UNIQUE / unique index / FOREIGN KEY containing the tenant column) --
 *     replaced by inserting tenant A's row verbatim under tenant B and requiring it to SUCCEED;
 *   - relkind semantics (a materialized view or foreign table cannot be policed; a view is safe only when it is
 *     `security_invoker` or its owner cannot bypass) -- replaced by reading across tenants from whatever it is;
 *   - role attributes (`rolsuper`, `rolbypassrls`, `rolreplication`, reachable by membership) -- replaced by
 *     attempting `SET ROLE` into every reachable role and then trying to read;
 *   - `relrowsecurity`, `relforcerowsecurity`, policy canonicality, `polcmd` and `polroles` -- replaced by the
 *     read/write/re-parent/delete attacks and by the POSITIVE control, which fails if the runtime role cannot read
 *     its own rows;
 *   - the TRUNCATE privilege -- replaced by attempting `TRUNCATE`, then `TRUNCATE ... CASCADE`.
 *
 * It still needs a database, which is inherent: a schema cannot be read from source. It FAILS rather than skipping
 * when it cannot look, for the reason the whole of this file exists.
 */

const REPO_ROOT = __DIR__ . '/../..';

/*
 * A four-line PSR-4 autoloader rather than `vendor/autoload.php`, so this gate keeps the property every other
 * gate here has: it needs nothing INSTALLED. It does need a database -- that is inherent, since a schema cannot
 * be read from source -- but a missing vendor tree should not be a second reason it cannot run. A bare
 * `require_once` is not enough: `PostgresRowLevelSecurityIsolation` implements `TenantIsolationStrategy`, so the
 * interface has to be resolvable too.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Twes\\')) {
        return;
    }

    $path = REPO_ROOT . '/api/src/' . str_replace('\\', '/', substr($class, strlen('Twes\\'))) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;

/**
 * Column names that plausibly mean "the tenant" but are NOT the one this project uses.
 *
 * A table carrying one of these is REFUSED as unclassifiable rather than skipped: the author either meant it as
 * the tenant, in which case it must be renamed to `TENANT_COLUMN` so every check can find it, or did not, in
 * which case saying so is one line. Silence is the only wrong answer.
 *
 * **Deliberately excludes `customer_id`, `client_id` and `account_id`.** In an invoicing product those are
 * ordinary business references — a document belongs to a customer — so treating them as candidate tenant columns
 * would refuse legitimate schemas and train the next author to widen the gate rather than fix a table. The line
 * is drawn at names that could only mean the tenant of a multi-tenant system.
 */
const TENANT_COLUMN_LOOKALIKES = [
    'tenant',
    'tenant_id',
    'tenantid',
    'tenant_uuid',
    'company',
    'companyid',
    'company_uuid',
    'org_id',
    'organisation_id',
    'organization_id',
];

/**
 * Relation kinds that can carry a NOT NULL constraint at all.
 *
 * A materialized view, a foreign table and a view do NOT: `pg_attribute.attnotnull` is false on their columns
 * whatever the underlying table says, so applying assertion 1 to them would report a violation on every correct
 * matview in the schema. The OWNERSHIP assertion is deliberately not scoped this way — see the file docblock.
 */
const NOT_NULL_CAPABLE_RELKINDS = ['r', 'p'];

if (($argv[1] ?? '') === '--dump-rules') {
    printf("tenant_column\t%s\n", PostgresRowLevelSecurityIsolation::TENANT_COLUMN);

    foreach (TENANT_COLUMN_LOOKALIKES as $lookalike) {
        printf("lookalike\t%s\n", $lookalike);
    }

    foreach (NOT_NULL_CAPABLE_RELKINDS as $relkind) {
        printf("not_null_capable_relkind\t%s\n", $relkind);
    }

    exit(0);
}

$dsn = getenv('TWES_SCHEMA_DSN') ?: getenv('TWES_TEST_DSN');
$user = getenv('TWES_SCHEMA_USER') ?: getenv('TWES_TEST_DB_SUPERUSER');
$password = getenv('TWES_SCHEMA_PASSWORD') ?: getenv('TWES_TEST_DB_SUPERUSER_PASSWORD');
$runtimeRole = getenv('TWES_SCHEMA_RUNTIME_ROLE') ?: getenv('TWES_TEST_DB_USER') ?: 'twes';

if (!is_string($dsn) || '' === $dsn || !is_string($user) || '' === $user) {
    fwrite(STDERR, "schema-tenancy: FAIL — no database to inspect.\n"
        . "  This gate reads a REAL MIGRATED SCHEMA; it is the only one here that does, and it cannot be\n"
        . "  satisfied by reading code. Set TWES_SCHEMA_DSN and TWES_SCHEMA_USER (TWES_SCHEMA_PASSWORD if the\n"
        . "  role needs one), or let it fall back to the integration suite's TWES_TEST_DSN /\n"
        . "  TWES_TEST_DB_SUPERUSER pair.\n"
        . "  It FAILS rather than skipping, deliberately: a NULLABLE tenant column is invisible to every other\n"
        . "  check in this repository — including the behavioural attack suite, since a row nobody can see looks\n"
        . "  exactly like a row nobody may see. CLAUDE.md § Gotchas records four controls that silently did not\n"
        . "  run.\n");

    exit(1);
}

try {
    $connection = new PDO($dsn, $user, '' === (string) $password ? null : (string) $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $failure) {
    fwrite(STDERR, 'schema-tenancy: FAIL — could not connect: ' . $failure->getMessage() . "\n"
        . "  Wrong credentials produce the same silent green as missing ones, so this fails rather than\n"
        . "  skipping. Note this container runs PostgreSQL clusters 16 and 18 BOTH configured on 5432, so a\n"
        . "  `password authentication failed` here may mean the wrong cluster won the port rather than a wrong\n"
        . "  password: `pg_lsclusters`, then `pg_ctlcluster 18 main start`.\n");

    exit(1);
}

/*
 * THE RUNTIME ROLE MUST EXIST, checked before anything else uses it.
 *
 * The ownership assertion is named after this role, and it degrades SILENTLY when the name is wrong: a role that
 * does not exist can never be found owning anything, so `roleIsReachableBySql()` answers false for every relation
 * and the axis reports clean over a schema it never checked. A silent pass on a security axis is the shape
 * CLAUDE.md § Gotchas records repeatedly.
 *
 * It is easy to get wrong rather than a theoretical concern: the name falls back through
 * `TWES_SCHEMA_RUNTIME_ROLE`, then `TWES_TEST_DB_USER`, then the literal `twes`. Any deployment whose runtime role
 * is called something else and sets neither variable silently checks a role that does not exist.
 */
$roleExists = $connection->prepare('SELECT true FROM pg_roles WHERE rolname = ?');
$roleExists->execute([$runtimeRole]);

if (false === $roleExists->fetchColumn()) {
    fwrite(STDERR, sprintf(
        "schema-tenancy: FAIL — the runtime role \"%s\" does not exist in this database.\n"
        . "  The ownership assertion here is named after it, and a role that does not exist can never be found\n"
        . "  owning a relation — so that axis would report CLEAN over a schema it never checked. Set\n"
        . "  TWES_SCHEMA_RUNTIME_ROLE to the role the application actually connects as; it falls back to\n"
        . "  TWES_TEST_DB_USER and then to the literal \"twes\".\n",
        $runtimeRole,
    ));

    exit(1);
}

// A single-quoted SQL literal for the runtime role. The reachability predicate below is anchored on the NAMED role
// rather than on this connection, because this connection is a superuser and every "can you reach it" question
// would otherwise answer yes.
$runtimeRoleLiteral = "'" . str_replace("'", "''", $runtimeRole) . "'";
$tenantColumn = PostgresRowLevelSecurityIsolation::TENANT_COLUMN;

$tables = $connection->query(
    'SELECT c.relname AS table_name, '
    . 'c.relkind AS kind, '
    . 'n.nspname AS schema_name, '
    . 'o.rolname AS owner, '
    // REACHABILITY, not string equality -- and computed by the SAME predicate the runtime checker uses, taken
    // from the class rather than rewritten here. `$row['owner'] === $runtimeRole` was a reproduced cross-tenant
    // read: a relation owned by a role the runtime role can `SET ROLE` to passed, and DISABLE ROW LEVEL SECURITY
    // was then one statement away. A role is a member of itself, so this covers "is the owner" too.
    . '(' . PostgresRowLevelSecurityIsolation::roleIsReachableBySql($runtimeRoleLiteral, 'c.relowner')
    . ') AS owner_reachable, '
    . '(SELECT json_agg(json_build_object('
    . "    'name', a.attname, 'not_null', a.attnotnull"
    . ' )) FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped'
    . ') AS columns '
    . 'FROM pg_class c '
    . 'JOIN pg_namespace n ON n.oid = c.relnamespace '
    . 'JOIN pg_roles o ON o.oid = c.relowner '
    /*
     * EVERY non-system schema, and five relkinds -- not `nspname = 'public'` and not `('r','p')`.
     *
     * Both narrowings were reproduced leaks. The old scope made this gate NARROWER than the runtime checker it
     * exists to backstop, so a tenant table in `reporting` was invisible to BOTH. And the OWNERSHIP assertion
     * applies to a view and a materialized view as much as to a table: one owned by the runtime role proves the
     * same thing about the connection that created it.
     *
     * The toast and temp guards are belt-and-braces: those hold relkinds we do not select, but a filter that
     * depends on another filter for its correctness is how the next widening reintroduces something.
     */
    . "WHERE c.relkind IN ('r', 'p', 'm', 'f', 'v') "
    . "AND n.nspname NOT IN ('pg_catalog', 'information_schema') "
    . "AND n.nspname NOT LIKE 'pg_toast%' AND n.nspname NOT LIKE 'pg_temp%' "
    . 'ORDER BY 1',
);

if (false === $tables) {
    fwrite(STDERR, "schema-tenancy: FAIL — could not read pg_class.\n");

    exit(1);
}

/** @var list<array{table_name: string, kind: string, schema_name: string, owner: string, owner_reachable: bool|string, columns: string}> $rows */
$rows = $tables->fetchAll(PDO::FETCH_ASSOC);

$inspected = 0;
$tenantOwned = 0;
$violations = [];

foreach ($rows as $row) {
    ++$inspected;
    // SCHEMA-QUALIFIED, since more than one schema is in scope: `archive` and `reporting.archive` are different
    // relations and a message naming only the second half sends a reader to the wrong one.
    $table = 'public' === $row['schema_name']
        ? $row['table_name']
        : $row['schema_name'] . '.' . $row['table_name'];

    /*
     * ASSERTION 2, FIRST and for EVERY relation rather than only the tenant-owned ones -- see the file docblock.
     * Placed before the classification branch on purpose: the `continue`s below skip relations this check must
     * still see, which is precisely how it came to miss a runtime-owned `doctrine_migration_versions`.
     *
     * `!isFalse(...)`, the fail-CLOSED direction: an unrecognised spelling must report a violation rather than
     * wave the relation through. The danger is if TRUE, so the complement of isTrue() is the wrong member here.
     */
    if (!isFalse($row['owner_reachable'])) {
        $violations[] = sprintf(
            '%s is OWNED by "%s", which the runtime role "%s" IS or can SET ROLE to. FORCE stops an owner '
            . 'skipping policies, not removing them: '
            . '`ALTER TABLE %s DISABLE ROW LEVEL SECURITY` is one statement away, and no attack can observe that '
            . 'until somebody runs it. Migrations must run as a separate owning role that is never granted to the '
            . 'runtime role — configure a second Doctrine connection for them rather than reusing DATABASE_URL. '
            . 'This is refused even when the relation holds no tenant data, because it proves the migration '
            . 'connection is the runtime role, so the NEXT tenant-owned table it creates will be owned by it too.',
            $table,
            $row['owner'],
            $runtimeRole,
            $table,
        );
    }

    /** @var list<array{name: string, not_null: bool|string}> $columns */
    $columns = json_decode($row['columns'], true, 512, \JSON_THROW_ON_ERROR);
    $columnNames = array_map(static fn(array $column): string => $column['name'], $columns);

    $hasTenantColumn = in_array($tenantColumn, $columnNames, true);
    $lookalikes = array_values(array_intersect($columnNames, TENANT_COLUMN_LOOKALIKES));

    if (!$hasTenantColumn && [] !== $lookalikes) {
        $violations[] = sprintf(
            '%s carries %s but no "%s", so neither this gate nor the behavioural attack suite can tell whether it '
            . 'holds tenant data — and the suite attacks exactly what this discovery reports, so an unclassified '
            . 'relation goes UNATTACKED. Either rename the column to "%s" so every tenancy check can find it, or '
            . '— if it genuinely is not a tenant — say so by adding the relation to this gate\'s reasoning. '
            . 'Refused rather than skipped.',
            $table,
            implode(' and ', array_map(static fn(string $c): string => '"' . $c . '"', $lookalikes)),
            $tenantColumn,
            $tenantColumn,
        );

        continue;
    }

    if (!$hasTenantColumn) {
        // Genuinely not tenant data -- `doctrine_migration_versions` is the obvious one. Counted, not asserted,
        // and note assertion 2 above has ALREADY run on it.
        continue;
    }

    ++$tenantOwned;

    /*
     * ASSERTION 1: the tenant column is NOT NULL.
     *
     * Scoped to relkinds that can carry the constraint at all -- see NOT_NULL_CAPABLE_RELKINDS. A matview holding
     * tenant data is a real defect, but it is one the behavioural suite reports by reading another tenant's rows
     * out of it, which is both stronger evidence and a message a reader can act on.
     */
    if (!in_array($row['kind'], NOT_NULL_CAPABLE_RELKINDS, true)) {
        continue;
    }

    foreach ($columns as $column) {
        if ($column['name'] === $tenantColumn && !isTrue($column['not_null'])) {
            $violations[] = sprintf(
                '%s.%s is NULLABLE. Under the canonical predicate a NULL tenant matches nobody, so such a row is '
                . 'invisible to every tenant including the one that wrote it — data loss wearing the appearance '
                . 'of isolation, and the one failure mode here that NO attack can reveal: an attacker hunting for '
                . 'another tenant\'s rows and the legitimate owner looking for its own both see nothing, which is '
                . 'indistinguishable from correct isolation.',
                $table,
                $tenantColumn,
            );
        }
    }
}

// Printed UNCONDITIONALLY and before the verdict, so the meta-suite can prove the gate looked at something. A
// gate that inspected an EMPTY schema reports OK indistinguishably from one that inspected a migrated database.
printf("counts — tables=%d tenant_owned=%d violations=%d\n", $inspected, $tenantOwned, count($violations));

/*
 * VIOLATIONS FIRST, then the anti-vacuity check.
 *
 * The order was the other way round, so a run that found a violation AND classified nothing as tenant-owned
 * printed `violations=1` in the counts line, never printed the violation, and blamed the wrong cause -- telling
 * the reader to check their DSN when the real answer was "rename `tenant_id`". Exit code was right and the
 * diagnosis was not, which is the wrong-bound-message shape CLAUDE.md records for `document.quantity_too_large`.
 */
if ([] !== $violations) {
    fwrite(STDERR, "schema-tenancy: FAIL — the schema does not satisfy tenant isolation.\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, '  ' . $violation . "\n");
    }

    exit(1);
}

if (0 === $tenantOwned) {
    fwrite(STDERR, "schema-tenancy: FAIL — found NO tenant-owned relation, so this gate asserted nothing.\n"
        . "  Either the database was never migrated, or it is the wrong database. Both look identical to a\n"
        . "  passing run unless this is checked, which is why it is.\n");

    exit(1);
}

/*
 * THE MESSAGE NAMES ONLY WHAT WAS CHECKED, narrowed as each judgement moved to the behavioural suite.
 *
 * The previous version claimed the tenant-owned tables were "enabled, FORCED, canonically policed, NOT NULL, and
 * beyond ownership and TRUNCATE" — five of which this gate no longer looks at. A control whose success message
 * overclaims is one the next reader stops believing, and `build-waves.plan.md` made narrowing this sentence part
 * of the ruling rather than an afterthought.
 */
printf(
    "schema-tenancy: OK — %d tenant-owned relation(s) of %d have a NOT NULL \"%s\", and no relation is owned by "
    . "\"%s\". Isolation itself is proven by attack in BehaviouralIsolationTest.\n",
    $tenantOwned,
    $inspected,
    $tenantColumn,
    $runtimeRole,
);

/** See PostgresRowLevelSecurityIsolation::isTrue() — pdo_pgsql renders booleans as bools or as `t`/`f`. */
function isTrue(bool|string $value): bool
{
    return true === $value || 't' === $value || '1' === $value;
}

/**
 * The deliberate non-complement of {@see isTrue()} — see `PostgresRowLevelSecurityIsolation::isFalse()`.
 *
 * Used for ownership reachability, where TRUE is the danger: an unrecognised spelling must report a violation
 * rather than clear the role. Round 20 found five call sites in the runtime checker with the wrong member of this
 * pair, one of them inverted by a `(string)` cast, so the distinction is repeated here rather than assumed.
 */
function isFalse(bool|string $value): bool
{
    return false === $value || 'f' === $value || '0' === $value;
}
