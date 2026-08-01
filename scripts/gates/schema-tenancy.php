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
 * SCHEMA TENANCY: every tenant-owned table is actually isolated, checked against a REAL MIGRATED SCHEMA.
 *
 * WHY THIS EXISTS, and why it is the one gate here that needs a database. Every other gate in this directory
 * reads CODE. None reads the SCHEMA — so a migration that simply omits `ENABLE ROW LEVEL SECURITY` produces a
 * tenant-owned table that is completely unpoliced, and **no existing check can see it**:
 * `assertPolicedTablesAreBeyondThisRolesReach()` derives its subject set from tables that already HAVE row
 * security, so a table without it is invisible to that check by construction. Round 7 filed that precisely, and
 * `build-waves.plan.md` makes this gate a Wave 1 BLOCKER for it — the first migration does not land without it.
 *
 * It could not be written until there was a migrated schema to check, and the plan says so explicitly: *"a gate
 * with nothing to check is untestable"*. That is now false, so here it is.
 *
 * WHAT IT ASSERTS, per tenant-owned table:
 *   1. `relrowsecurity` — row security is enabled at all;
 *   2. `relforcerowsecurity` — it applies to the table's OWNER too, without which a migration connection reads
 *      every tenant;
 *   3. a PERMISSIVE policy whose USING and WITH CHECK are both the canonical tenant predicate, and no permissive
 *      policy that is not — a permissive policy is ORed, so one unscoped one reopens the whole table;
 *   4. the tenant column is NOT NULL — a NULL `company_id` matches no tenant under the canonical predicate, so
 *      the row becomes invisible to everyone including its owner, which is data loss that looks like isolation;
 *   5. the runtime role does not hold TRUNCATE on it. TRUNCATE ignores row security entirely.
 *
 * AND ONE THING IT ASSERTS ON EVERY TABLE, tenant-owned or not:
 *   6. the runtime role does not OWN it. `FORCE` stops an owner *skipping* policies, not *removing* them: an
 *      owner can `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` in one statement. That was a real P0 (round 4)
 *      found asserted in prose and enforced nowhere.
 *
 *      **This one is deliberately NOT scoped to tenant-owned tables, and that scoping was a real gap.** A table
 *      with no tenant column leaks nothing by itself, so the obvious reading is that its owner does not matter.
 *      What matters is what it PROVES about the connection that created it: if migrations run as the runtime
 *      role, the next tenant-owned table they create is owned by the runtime role. That is not hypothetical —
 *      `doctrine_migration_versions` in the local `twes_in` database was exactly this on 2026-08-01, because
 *      `.env`'s `DATABASE_URL` named the runtime role while the comment beside it claimed migrations used a
 *      different one. The gate skipped it, having classified it as "not tenant data, counted not asserted".
 *      A precursor to a P0 is worth refusing while it is still only a precursor.
 *
 * The canonical predicate is not written out here. It comes from `canonicalPolicyExpression()`, the same source
 * the migration uses through `policySqlFor()` and the same one the runtime checker compares against — so all
 * three agree by construction rather than by review. Round 12 found a policy that MENTIONED `twes.tenant_id`
 * without isolating by it, which is exactly what a second copy of the predicate invites.
 *
 * **IT FAILS ON A TABLE IT CANNOT CLASSIFY, rather than skipping.** A table with a column that plausibly MEANS
 * the tenant but is not `company_id` — `tenant_id`, `org_id` — is neither obviously tenant-owned nor obviously
 * not, so it is refused with the two ways to resolve it. Skipping would be the fourth instance of the shape
 * CLAUDE.md § Gotchas records: a control that silently does not run is worse than one openly owed.
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

if (($argv[1] ?? '') === '--dump-rules') {
    printf("tenant_column\t%s\n", PostgresRowLevelSecurityIsolation::TENANT_COLUMN);

    foreach (TENANT_COLUMN_LOOKALIKES as $lookalike) {
        printf("lookalike\t%s\n", $lookalike);
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
        . "  It FAILS rather than skipping, deliberately: an unpoliced tenant table is invisible to every other\n"
        . "  check in this directory, so a skipped run here reports a clean bill over the one thing nothing else\n"
        . "  can see. CLAUDE.md § Gotchas records four separate controls that silently did not run.\n");

    exit(1);
}

try {
    $connection = new PDO($dsn, $user, '' === (string) $password ? null : (string) $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $failure) {
    fwrite(STDERR, "schema-tenancy: FAIL — could not connect: " . $failure->getMessage() . "\n"
        . "  Wrong credentials produce the same silent green as missing ones, so this fails rather than\n"
        . "  skipping. Note this container runs PostgreSQL clusters 16 and 18 BOTH configured on 5432, so a\n"
        . "  `password authentication failed` here may mean the wrong cluster won the port rather than a wrong\n"
        . "  password: `pg_lsclusters`, then `pg_ctlcluster 18 main start`.\n");

    exit(1);
}

/*
 * THE RUNTIME ROLE MUST EXIST, checked before anything else uses it.
 *
 * Both assertions that reference this role degrade when the name is wrong, and in OPPOSITE directions -- which is
 * what makes a wrong name worse than an obviously broken one. `$row['owner'] === $runtimeRole` simply never
 * matches, so the ownership axis reports clean over a schema it never checked; `has_table_privilege('typo', …)`
 * raises SQLSTATE 42704 and the gate dies with an uncaught PDOException and exit 255. One axis lies, the other
 * crashes, and a crash is indistinguishable from a detection to anything reading exit codes.
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
        . "  Both runtime-role assertions here are named after it: a role that does not exist can never be found\n"
        . "  owning a table, so the ownership axis would report CLEAN over a schema it never checked, while the\n"
        . "  TRUNCATE probe would raise and exit 255. Set TWES_SCHEMA_RUNTIME_ROLE to the role the application\n"
        . "  actually connects as — it falls back to TWES_TEST_DB_USER and then to the literal \"twes\".\n",
        $runtimeRole,
    ));

    exit(1);
}

$canonical = PostgresRowLevelSecurityIsolation::canonicalPolicyExpression();
$tenantColumn = PostgresRowLevelSecurityIsolation::TENANT_COLUMN;

$tables = $connection->query(
    "SELECT c.relname AS table_name, "
    . 'c.relrowsecurity AS rls_enabled, '
    . 'c.relforcerowsecurity AS forced, '
    . 'o.rolname AS owner, '
    . '(SELECT json_agg(json_build_object('
    . "    'name', a.attname, 'not_null', a.attnotnull"
    . ' )) FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped'
    . ') AS columns, '
    . '(SELECT coalesce(json_agg(json_build_object('
    . "    'name', p.polname,"
    . "    'permissive', p.polpermissive,"
    . "    'qual', pg_get_expr(p.polqual, p.polrelid),"
    . "    'check', pg_get_expr(p.polwithcheck, p.polrelid)"
    . " )), '[]') FROM pg_policy p WHERE p.polrelid = c.oid) AS policies "
    . 'FROM pg_class c '
    . 'JOIN pg_namespace n ON n.oid = c.relnamespace '
    . 'JOIN pg_roles o ON o.oid = c.relowner '
    . "WHERE c.relkind IN ('r', 'p') AND n.nspname = 'public' "
    . 'ORDER BY 1',
);

if (false === $tables) {
    fwrite(STDERR, "schema-tenancy: FAIL — could not read pg_class.\n");

    exit(1);
}

/** @var list<array{table_name: string, rls_enabled: bool|string, forced: bool|string, owner: string, columns: string, policies: string}> $rows */
$rows = $tables->fetchAll(PDO::FETCH_ASSOC);

$inspected = 0;
$tenantOwned = 0;
$violations = [];

foreach ($rows as $row) {
    ++$inspected;
    $table = $row['table_name'];

    // FIRST, and for EVERY table rather than only the tenant-owned ones -- see assertion 6 in this file's
    // docblock for why a non-tenant table's owner is load-bearing. Placed before the classification branch on
    // purpose: the two `continue`s below skip a table this check must still see, which is precisely how it came
    // to miss a runtime-owned `doctrine_migration_versions`.
    if ($row['owner'] === $runtimeRole) {
        $violations[] = sprintf(
            '%s is OWNED by the runtime role "%s". FORCE stops an owner skipping policies, not removing them: '
            . '`ALTER TABLE %s DISABLE ROW LEVEL SECURITY` is one statement away. Migrations must run as a '
            . 'separate owning role that is never granted to the runtime role — configure a second Doctrine '
            . 'connection for them rather than reusing DATABASE_URL. This is refused even when the table holds '
            . 'no tenant data, because it proves the migration connection is the runtime role, so the NEXT '
            . 'tenant-owned table it creates will be owned by it too.',
            $table,
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
            '%s carries %s but no "%s", so this gate cannot tell whether it holds tenant data. Either rename the '
            . 'column to "%s" so every tenancy check can find it, or — if it genuinely is not a tenant — say so '
            . 'by adding the table to this gate\'s reasoning. Refused rather than skipped: an unpoliced tenant '
            . 'table is invisible to every other check here.',
            $table,
            implode(' and ', array_map(static fn(string $c): string => '"' . $c . '"', $lookalikes)),
            $tenantColumn,
            $tenantColumn,
        );

        continue;
    }

    if (!$hasTenantColumn) {
        // Genuinely not tenant data -- `doctrine_migration_versions` is the obvious one. Counted, not asserted.
        continue;
    }

    ++$tenantOwned;

    if (!isTrue($row['rls_enabled'])) {
        $violations[] = sprintf(
            '%s holds tenant data and has NO row-level security. Nothing else in this repository can see this: '
            . 'the runtime isolation check derives its subject set from tables that already have RLS, so an '
            . 'unpoliced table is invisible to it by construction. Emit the statements with policySqlFor().',
            $table,
        );

        continue;
    }

    if (!isTrue($row['forced'])) {
        $violations[] = sprintf(
            '%s has row-level security but not FORCE. Policies do not apply to a table\'s OWNER without it, and '
            . 'migrations run as the owner — so every migration and any support tooling reads every tenant.',
            $table,
        );
    }

    foreach ($columns as $column) {
        if ($column['name'] === $tenantColumn && !isTrue($column['not_null'])) {
            $violations[] = sprintf(
                '%s.%s is NULLABLE. Under the canonical predicate a NULL tenant matches nobody, so such a row is '
                . 'invisible to every tenant including the one that wrote it — data loss wearing the appearance '
                . 'of isolation, and the one failure mode here that no cross-tenant read would ever reveal.',
                $table,
                $tenantColumn,
            );
        }
    }

    /** @var list<array{name: string, permissive: bool|string, qual: ?string, check: ?string}> $policies */
    $policies = json_decode($row['policies'], true, 512, \JSON_THROW_ON_ERROR);
    $canonicalPolicies = 0;

    foreach ($policies as $policy) {
        if (!isTrue($policy['permissive'])) {
            // RESTRICTIVE policies are ANDed, so an unscoped one only ever narrows access. Never a bypass.
            continue;
        }

        $qualIsCanonical = null !== $policy['qual'] && expressionMatches($policy['qual'], $canonical);
        $checkIsCanonical = null !== $policy['check'] && expressionMatches($policy['check'], $canonical);

        if ($qualIsCanonical && $checkIsCanonical) {
            ++$canonicalPolicies;

            continue;
        }

        $violations[] = sprintf(
            '%s has a PERMISSIVE policy "%s" that is not the canonical tenant predicate on both halves '
            . '(USING %s, WITH CHECK %s). Permissive policies are ORed, so one unscoped policy reopens the whole '
            . 'table however correct the others are — and BOTH halves matter: PostgreSQL reuses USING as a write '
            . 'check for UPDATE, but a plain INSERT is guarded by WITH CHECK alone.',
            $table,
            $policy['name'],
            $qualIsCanonical ? 'ok' : 'NOT canonical: ' . var_export($policy['qual'], true),
            $checkIsCanonical ? 'ok' : 'NOT canonical: ' . var_export($policy['check'], true),
        );
    }

    if (0 === $canonicalPolicies) {
        $violations[] = sprintf(
            '%s has row-level security enabled and NO canonical tenant policy. With RLS on and no applicable '
            . 'policy the table reads as empty, so this fails closed rather than leaking — but it is still a '
            . 'broken table, and it is the shape a half-written migration leaves behind.',
            $table,
        );
    }

    $truncate = $connection->query(sprintf(
        "SELECT has_table_privilege('%s', '%s', 'TRUNCATE') AS can_truncate",
        str_replace("'", "''", $runtimeRole),
        str_replace("'", "''", $table),
    ));

    if (false === $truncate) {
        $violations[] = sprintf('%s: could not read TRUNCATE privilege for "%s".', $table, $runtimeRole);

        continue;
    }

    // **THE RAW VALUE, never `(string) $granted`** — and this gate reproduced round 20's P0 while being written,
    // which is why the warning is repeated here rather than assumed learned. `(string) false` is the EMPTY
    // STRING, `isFalse('')` is false, so `!isFalse((string) ...)` is TRUE for a false result: the gate reported
    // all four tables TRUNCATE-able on a schema where `has_table_privilege` said false and the ACL was
    // `twes=arwd/twes_owner` with no `D`. An hour after the § Gotchas entry about exactly this.
    $granted = $truncate->fetchColumn();
    $definitelyNotGranted = is_bool($granted) || is_string($granted) ? isFalse($granted) : false;

    if (!$definitelyNotGranted) {
        $violations[] = sprintf(
            '%s: the runtime role "%s" holds TRUNCATE. TRUNCATE ignores row-level security entirely, so it '
            . 'erases every tenant\'s rows in one statement — round 5 did exactly that while the isolation check '
            . 'reported the connection clean.',
            $table,
            $runtimeRole,
        );
    }
}

// Printed UNCONDITIONALLY and before the verdict, so the meta-suite can prove the gate looked at something. A
// gate that inspected an EMPTY schema reports OK indistinguishably from one that inspected a migrated database.
printf("counts — tables=%d tenant_owned=%d violations=%d\n", $inspected, $tenantOwned, count($violations));

if (0 === $tenantOwned) {
    fwrite(STDERR, "schema-tenancy: FAIL — found NO tenant-owned table, so this gate asserted nothing.\n"
        . "  Either the database was never migrated, or it is the wrong database. Both look identical to a\n"
        . "  passing run unless this is checked, which is why it is.\n");

    exit(1);
}

if ([] !== $violations) {
    // "a tenant table is not isolated" was the header until the ownership check widened to every table, at which
    // point it named the wrong thing for the one violation class that is NOT about a tenant table -- the
    // wrong-bound message defect CLAUDE.md § "Translation keys" records for `document.quantity_too_large`. The
    // per-violation lines below say which table and why; this line no longer asserts a category it cannot know.
    fwrite(STDERR, "schema-tenancy: FAIL — the schema does not satisfy tenant isolation.\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, '  ' . $violation . "\n");
    }

    exit(1);
}

printf(
    "schema-tenancy: OK — %d tenant-owned table(s) of %d are enabled, FORCED, canonically policed, NOT NULL, and "
    . "beyond \"%s\"'s ownership and TRUNCATE.\n",
    $tenantOwned,
    $inspected,
    $runtimeRole,
);

/**
 * Whether two policy expressions are the same predicate.
 *
 * PostgreSQL renders a stored expression back through its own deparser, so the text is canonical but not
 * necessarily character-identical to what was submitted — it adds casts and parentheses. Comparison is therefore
 * on the deparsed forms of BOTH sides: the expected side is round-tripped through the same deparser by the caller
 * that produced it. Whitespace is normalised because that is the one difference the deparser does not settle.
 */
function expressionMatches(string $actual, string $expected): bool
{
    $normalise = static fn(string $expression): string => preg_replace('/\s+/', ' ', trim($expression)) ?? $expression;

    return $normalise($actual) === $normalise($expected);
}

/** See PostgresRowLevelSecurityIsolation::isTrue() — pdo_pgsql renders booleans as bools or as `t`/`f`. */
function isTrue(bool|string $value): bool
{
    return true === $value || 't' === $value || '1' === $value;
}

/**
 * The deliberate non-complement of {@see isTrue()} — see `PostgresRowLevelSecurityIsolation::isFalse()`.
 *
 * Used for TRUNCATE, where TRUE is the danger: an unrecognised spelling must report a violation rather than
 * clear the role. Round 20 found five call sites in the runtime checker with the wrong member of this pair, one
 * of them inverted by a `(string)` cast, so the distinction is repeated here rather than assumed.
 */
function isFalse(bool|string $value): bool
{
    return false === $value || 'f' === $value || '0' === $value;
}
