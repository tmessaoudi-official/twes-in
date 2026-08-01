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
$roleExists = $connection->prepare(
    'SELECT rolsuper, rolbypassrls, rolreplication FROM pg_roles WHERE rolname = ?',
);
$roleExists->execute([$runtimeRole]);
/** @var array{rolsuper: bool|string, rolbypassrls: bool|string, rolreplication: bool|string}|false $role */
$role = $roleExists->fetch(PDO::FETCH_ASSOC);

if (false === $role) {
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

/*
 * AND THE ROLE MUST BE SUBJECT TO POLICIES AT ALL, which existing does not imply.
 *
 * The guard above was added to catch a wrong role NAME and stopped there, so it asked whether the role was SPELLED
 * correctly and not whether it was governed by the policies this gate then certifies. A reviewer set `BYPASSRLS`
 * and watched the gate report "enabled, FORCED, canonically policed, NOT NULL, and beyond … ownership and
 * TRUNCATE" over a role that reads every tenant with every policy in place.
 *
 * `roleCanBypassPolicies()` rather than three comparisons here: it already exists, it already covers REPLICATION
 * -- which goes AROUND the query layer policies live in rather than defeating them, via `pg_basebackup` -- and a
 * second copy of that judgement is the exact mistake this commit is undoing two axes over.
 */
if (PostgresRowLevelSecurityIsolation::roleCanBypassPolicies($role)) {
    fwrite(STDERR, sprintf(
        "schema-tenancy: FAIL — the runtime role \"%s\" can BYPASS row-level security"
        . " (rolsuper=%s rolbypassrls=%s rolreplication=%s).\n"
        . "  Every other assertion in this gate is then meaningless: policies remain in place and are simply not\n"
        . "  applied to this role, so a schema that is genuinely isolated certifies clean while the application\n"
        . "  reads every tenant. REPLICATION counts because it reads the heap directly through pg_basebackup,\n"
        . "  with row security never involved — certification round 5 recovered both tenants from a base backup\n"
        . "  taken by a role that was neither superuser nor BYPASSRLS.\n",
        $runtimeRole,
        var_export($role['rolsuper'], true),
        var_export($role['rolbypassrls'], true),
        var_export($role['rolreplication'], true),
    ));

    exit(1);
}

// A single-quoted SQL literal for the runtime role, built ONCE. Every reachability predicate below is anchored on
// the NAMED role rather than on this connection, because this connection is a superuser and every "can you reach
// it" question would otherwise answer yes.
$runtimeRoleLiteral = "'" . str_replace("'", "''", $runtimeRole) . "'";

$canonical = PostgresRowLevelSecurityIsolation::canonicalPolicyExpression();
$tenantColumn = PostgresRowLevelSecurityIsolation::TENANT_COLUMN;

$tables = $connection->query(
    "SELECT c.relname AS table_name, "
    . 'c.relkind AS kind, '
    . 'n.nspname AS schema_name, '
    . 'c.relrowsecurity AS rls_enabled, '
    . 'c.relforcerowsecurity AS forced, '
    . 'o.rolname AS owner, '
    // REACHABILITY, not string equality -- and computed by the SAME predicate the runtime checker uses, taken
    // from the class rather than rewritten here. `$row['owner'] === $runtimeRole` was a reproduced cross-tenant
    // read: a table owned by a role the runtime role can `SET ROLE` to passed, and DISABLE ROW LEVEL SECURITY was
    // then one statement away. A role is a member of itself, so this covers "is the owner" too.
    . '(' . PostgresRowLevelSecurityIsolation::roleIsReachableBySql($runtimeRoleLiteral, 'c.relowner')
    . ') AS owner_reachable, '
    // Likewise TRUNCATE. `has_table_privilege` resolves privileges INHERITABLY, while `SET ROLE` is authorised by
    // MEMBERSHIP -- so a grant made WITH INHERIT FALSE is invisible to it, and a reviewer erased every tenant's
    // rows through that gap while this gate printed "beyond … TRUNCATE". FALSE for the NULL-acl arm: a NULL acl
    // means owner-only defaults, so a non-owner reaches nothing.
    . '(' . PostgresRowLevelSecurityIsolation::privilegeIsReachableBySql(
        $runtimeRoleLiteral,
        'c.relacl',
        'TRUNCATE',
        false,
    ) . ') AS truncate_reachable, '
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
    /*
     * EVERY non-system schema, and four relkinds -- not `nspname = 'public'` and not `('r','p')`.
     *
     * Both narrowings were reproduced leaks. The old scope made this gate NARROWER than the runtime checker it
     * exists to backstop (`nspname NOT IN ('pg_catalog','information_schema')`), so a tenant table in
     * `reporting` was invisible to BOTH -- the runtime checker derives its subject set from tables that already
     * have row security, so an unpoliced one is invisible to it by construction. That is this gate's entire
     * charter, defeated one schema over.
     *
     * 'm' and 'f' are included in order to REFUSE them: a materialized view and a foreign table can carry no
     * policy at all, so one holding tenant data is an unpoliced copy by construction rather than a table someone
     * forgot to police. A reporting matview over `document` leaked both tenants while this gate printed OK.
     *
     * The toast and temp guards are belt-and-braces: those hold relkinds we do not select, but a filter that
     * depends on another filter for its correctness is how the next widening reintroduces something.
     */
    . "WHERE c.relkind IN ('r', 'p', 'm', 'f') "
    . "AND n.nspname NOT IN ('pg_catalog', 'information_schema') "
    . "AND n.nspname NOT LIKE 'pg_toast%' AND n.nspname NOT LIKE 'pg_temp%' "
    . 'ORDER BY 1',
);

if (false === $tables) {
    fwrite(STDERR, "schema-tenancy: FAIL — could not read pg_class.\n");

    exit(1);
}

/** @var list<array{table_name: string, rls_enabled: bool|string, forced: bool|string, owner: string, owner_reachable: bool|string, truncate_reachable: bool|string, kind: string, schema_name: string, columns: string, policies: string}> $rows */
$rows = $tables->fetchAll(PDO::FETCH_ASSOC);

$inspected = 0;
$tenantOwned = 0;
$violations = [];

foreach ($rows as $row) {
    ++$inspected;
    // SCHEMA-QUALIFIED, now that more than one schema is in scope: `archive` and `reporting.archive` are
    // different relations and a message naming only the second half sends a reader to the wrong one.
    $table = 'public' === $row['schema_name']
        ? $row['table_name']
        : $row['schema_name'] . '.' . $row['table_name'];

    // FIRST, and for EVERY table rather than only the tenant-owned ones -- see assertion 6 in this file's
    // docblock for why a non-tenant table's owner is load-bearing. Placed before the classification branch on
    // purpose: the two `continue`s below skip a table this check must still see, which is precisely how it came
    // to miss a runtime-owned `doctrine_migration_versions`.
    // `!isFalse(...)`, the fail-CLOSED direction: an unrecognised spelling must report a violation rather than
    // wave the table through. Danger is if TRUE, so the complement of isTrue() is the wrong member here -- see the
    // round-20 note further down on why these two are deliberate non-complements.
    if (!isFalse($row['owner_reachable'])) {
        $violations[] = sprintf(
            '%s is OWNED by "%s", which the runtime role "%s" IS or can SET ROLE to. FORCE stops an owner '
            . 'skipping policies, not removing them: '
            . '`ALTER TABLE %s DISABLE ROW LEVEL SECURITY` is one statement away. Migrations must run as a '
            . 'separate owning role that is never granted to the runtime role — configure a second Doctrine '
            . 'connection for them rather than reusing DATABASE_URL. This is refused even when the table holds '
            . 'no tenant data, because it proves the migration connection is the runtime role, so the NEXT '
            . 'tenant-owned table it creates will be owned by it too.',
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

    /*
     * A RELKIND THAT CAN NEVER BE POLICED, refused before the RLS checks rather than by them.
     *
     * `relrowsecurity` is false on a materialized view and a foreign table, so the next check would already fire
     * -- with a message prescribing `policySqlFor()`, which is impossible here and would send a reader to spend
     * an afternoon discovering that PostgreSQL supports no policy on either. The distinction is not pedantic: a
     * table missing its policy is a migration someone must finish, while a matview holding tenant data is a
     * DESIGN that cannot be made safe and has to be replaced by a policed table or a view.
     *
     * A matview is the dangerous one because `REFRESH` materialises rows under whichever tenant was bound at
     * refresh time, and every later reader sees that snapshot unfiltered. A plain view (relkind 'v') is NOT in
     * this set and is deliberately not selected at all: `FORCE ROW LEVEL SECURITY` subjects the view's owner to
     * the policy and `current_setting` is evaluated per query, so a view over a policed table stays scoped --
     * verified by a reviewer who tried to break it and could not.
     */
    if (in_array($row['kind'], ['m', 'f'], true)) {
        $violations[] = sprintf(
            '%s holds tenant data and is a %s, which cannot carry row-level security at all — PostgreSQL '
            . 'supports no policy on one, so this is an unpoliced copy of tenant data by construction rather '
            . 'than a relation that someone forgot to police. A REFRESH materialises rows under whichever '
            . 'tenant was bound at the time and every later reader sees that snapshot unfiltered. Replace it '
            . 'with a policed table, or with a plain VIEW over one — a view stays scoped, because FORCE subjects '
            . "the view's owner to the policy and the tenant is read per query.",
            $table,
            'm' === $row['kind'] ? 'MATERIALIZED VIEW' : 'FOREIGN TABLE',
        );

        continue;
    }

    /*
     * COMPOSITE KEYS: every PRIMARY KEY, UNIQUE constraint, unique INDEX and FOREIGN KEY on a tenant-owned table
     * must include the tenant column.
     *
     * **This belongs in a TENANCY gate, not a modelling one, and the reason is not obvious:** uniqueness and
     * foreign-key checks run with row-level security BYPASSED. They have to — PostgreSQL must see rows the
     * querying tenant cannot, or a constraint would only be enforceable against rows you can already read. So a
     * key that omits the tenant column is checked across EVERY tenant, and no policy narrows it:
     *
     *   - a UNIQUE index omitting it makes tenant B's insert fail because tenant A already used the value. That is
     *     a cross-tenant existence oracle AND a denial of service on somebody else's numbering.
     *   - a single-column FOREIGN KEY lets one tenant reference another's row, and `ON DELETE CASCADE` then
     *     deletes across the boundary. `document_line_belongs_to_document` is composite for exactly this reason,
     *     and the migration's own comment says so.
     *
     * Three round records call this "the composite-key schema gate" and rate it P0 at the first Wave 1 migration.
     * It read no `pg_constraint` and no `pg_index` at all until round 21, so the migration got this right and
     * nothing checked that the next one would.
     */
    $keys = $connection->prepare(
        "SELECT con.conname AS name, con.contype AS kind,"
        . ' (SELECT array_agg(att.attname ORDER BY att.attname) FROM pg_attribute att'
        . '  WHERE att.attrelid = con.conrelid AND att.attnum = ANY(con.conkey)) AS columns'
        . ' FROM pg_constraint con WHERE con.conrelid = ?::regclass'
        . "   AND con.contype IN ('p', 'u', 'f')"
        . ' UNION ALL'
        . " SELECT ic.relname AS name, 'i' AS kind,"
        . ' (SELECT array_agg(att.attname ORDER BY att.attname) FROM pg_attribute att'
        . '  WHERE att.attrelid = idx.indrelid AND att.attnum = ANY(idx.indkey)) AS columns'
        . ' FROM pg_index idx JOIN pg_class ic ON ic.oid = idx.indexrelid'
        . ' WHERE idx.indrelid = ?::regclass AND idx.indisunique'
        // The index BACKING a primary key or unique constraint is reported by pg_constraint already; counting it
        // twice would report the same defect under two names.
        . '   AND NOT EXISTS (SELECT 1 FROM pg_constraint c2 WHERE c2.conindid = idx.indexrelid)',
    );
    $keys->execute([$table, $table]);

    /** @var list<array{name: string, kind: string, columns: ?string}> $keyRows */
    $keyRows = $keys->fetchAll(PDO::FETCH_ASSOC);

    foreach ($keyRows as $key) {
        // A NULL column list means the catalogue gave us a key we could not resolve. Refused, not skipped: this is
        // the axis where a silent skip means "no cross-tenant FK found" over a key never examined.
        $keyColumns = null === $key['columns']
            ? []
            : array_map('trim', explode(',', trim($key['columns'], '{}')));

        if (in_array($tenantColumn, $keyColumns, true)) {
            continue;
        }

        $violations[] = sprintf(
            '%s holds tenant data and its %s "%s" (%s) does not include "%s". Uniqueness and foreign-key checks '
            . 'run with row-level security BYPASSED — they must, or a constraint could only be enforced against '
            . 'rows the querying tenant can already read — so this key is checked across EVERY tenant and no '
            . 'policy narrows it. %s',
            $table,
            match ($key['kind']) {
                'p' => 'PRIMARY KEY',
                'u' => 'UNIQUE constraint',
                'f' => 'FOREIGN KEY',
                default => 'UNIQUE index',
            },
            $key['name'],
            [] === $keyColumns ? 'columns unreadable' : implode(', ', $keyColumns),
            $tenantColumn,
            'f' === $key['kind']
                ? 'A single-column foreign key lets one tenant reference another tenant\'s row, and ON DELETE '
                  . 'CASCADE then deletes across the boundary. Make it composite on both sides.'
                : 'A unique key omitting the tenant makes one tenant\'s insert fail because another already used '
                  . 'the value: a cross-tenant existence oracle, and a denial of service on their numbering.',
        );
    }

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

    /*
     * TRUNCATE, read from the main query's reachability column rather than from a per-table `has_table_privilege`.
     *
     * That removes two defects at once. The predicate is now MEMBERSHIP-based, so a grant held `WITH INHERIT FALSE`
     * is visible -- a reviewer erased every tenant's rows through that gap while this gate printed "beyond …
     * TRUNCATE". And the table is no longer named as a TEXT literal: `has_table_privilege('Ledger', …)` case-folds
     * to `ledger`, which was a silent false negative on a mixed-case table and an uncaught PDOException with exit
     * 255 when only the mixed-case one existed. Joining on `c.oid` in the main query cannot mis-resolve a name,
     * and it is the same lesson as round 13's `current_user::regrole` downcasing, one axis over.
     *
     * **THE RAW VALUE, never `(string) $granted`** — this gate reproduced round 20's P0 while being written.
     * `(string) false` is the EMPTY STRING and `isFalse('')` is false, so `!isFalse((string) ...)` is TRUE for a
     * false result: the gate reported all four tables TRUNCATE-able where the ACL was `twes=arwd/twes_owner` with
     * no `D`. An hour after the § Gotchas entry about exactly this.
     */
    $granted = $row['truncate_reachable'];
    $definitelyNotGranted = isFalse($granted);

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
