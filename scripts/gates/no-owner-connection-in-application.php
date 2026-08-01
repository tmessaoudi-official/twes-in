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
 * THE OWNER CONNECTION IS FOR MIGRATIONS ONLY. Application code may never obtain it.
 *
 * `config/packages/doctrine.yaml` declares two DBAL connections. `default` connects as the restricted runtime role,
 * which owns nothing and cannot TRUNCATE. `owner` connects as the role that OWNS the tenant-owned tables, and
 * exists solely so `doctrine:migrations:migrate` has somewhere to run that is not the application's own credential.
 * That comment calls the split "a security boundary" — and certification round 21 showed it was not one.
 *
 * The container hands the owner connection out on request:
 *
 *     $ php bin/console debug:autowiring | grep -A1 Connection
 *      Doctrine\DBAL\Connection → doctrine.dbal.default_connection
 *        #[Target('default')] → doctrine.dbal.default_connection
 *        #[Target('owner')]   → doctrine.dbal.owner_connection
 *
 * So `#[Target('owner')] Connection $c`, or `$doctrine->getConnection('owner')`, is ONE LINE of ordinary
 * application code — the classic "fix the permission error" edit — and it yields a role that owns the tenant
 * tables, holds TRUNCATE, and can `DROP POLICY`. `FORCE ROW LEVEL SECURITY` does not help: it stops an owner
 * SKIPPING policies, not REMOVING them. A reviewer booted the kernel, called
 * `$registry->getConnection('owner')`, and turned off row security on `document` in one statement.
 *
 * WHY A GATE, and not the two alternatives considered:
 *
 *   - **Stripping the autowiring alias** would close `#[Target('owner')]` at container-compile time, which is
 *     mechanically stronger for that one vector — and leaves `$doctrine->getConnection('owner')` wide open, since
 *     the connection must stay in the registry for the migrations bundle to resolve it by name. Half a fix.
 *   - **Accepting and documenting it** is the shape `CLAUDE.md` § Gotchas now records five separate times: a
 *     control asserted in prose and enforced nowhere. The `.env` comment claiming "Migrations use a different
 *     role" while nothing implemented it is the most recent instance, and it is what created this connection pair.
 *
 * The accepted cost, stated plainly: this is a TEXT check over tracked source, so it cannot see a connection name
 * assembled at runtime (`getConnection($name)` where `$name` comes from configuration). That is not a hole worth
 * closing here — a dynamic connection name in this codebase would itself be the thing to reject in review, and a
 * gate that tried to prove absence-by-dataflow would be a static analyser rather than a grep. What it does catch is
 * every literal spelling, which is how this would actually be written.
 */

const REPO_ROOT = __DIR__ . '/../..';

/**
 * The literal spellings by which application code could reach the owner connection.
 *
 * Each is a distinct route rather than a variation of one: the attribute is resolved by the DI container, the
 * registry call by `ManagerRegistry`, and the service id by anything doing a raw `$container->get()`. Missing any
 * one of them leaves the boundary open, which is why they are enumerated as data and each gets its own generated
 * meta-case rather than being hand-picked.
 */
const OWNER_CONNECTION_SPELLINGS = [
    "Target('owner')",
    'Target("owner")',
    "getConnection('owner')",
    'getConnection("owner")',
    'doctrine.dbal.owner_connection',
    "getConnection('owner', ",
];

/**
 * Paths under which the owner connection is legitimately named.
 *
 * Deliberately EMPTY, and that is the point rather than an oversight: nothing in `api/src/` needs it. The
 * migrations bundle resolves the connection from `doctrine_migrations.yaml` through the container, never by a
 * reference in our own code, so there is no legitimate caller to exempt. If one ever appears it belongs here with
 * an argument in the commit message — the same rule `layer-dependencies.php` applies to its empty allowlist.
 *
 * @var list<string>
 */
const PERMITTED_PATHS = [];

if (($argv[1] ?? '') === '--dump-rules') {
    foreach (OWNER_CONNECTION_SPELLINGS as $spelling) {
        printf("spelling\t%s\n", $spelling);
    }

    foreach (PERMITTED_PATHS as $path) {
        printf("permitted\t%s\n", $path);
    }

    exit(0);
}

/*
 * `git ls-files`, never a recursive walk. A parallel certification round places agent worktrees at
 * `.claude/worktrees/<agent>/` — INSIDE the working tree — so each carries its own copy of every source file and a
 * `glob`/`find` sweep reads several repositories at once. Round 21's licence cross-check failed exactly that way,
 * with an "actual" list that was not wrong about this repository, it was reading four of them.
 */
exec('git -C ' . escapeshellarg(REPO_ROOT) . ' ls-files -z -- api/src', $output, $status);

if (0 !== $status) {
    fwrite(STDERR, "no-owner-connection: FAIL — could not list tracked files (git ls-files exited {$status}).\n");

    exit(1);
}

$files = array_values(array_filter(explode("\0", implode("\n", $output))));
$files = array_values(array_filter($files, static fn(string $f): bool => str_ends_with($f, '.php')));

$violations = [];
$scanned = 0;

foreach ($files as $file) {
    foreach (PERMITTED_PATHS as $permitted) {
        if (str_starts_with($file, $permitted)) {
            continue 2;
        }
    }

    $contents = file_get_contents(REPO_ROOT . '/' . $file);

    if (false === $contents) {
        $violations[] = sprintf('%s: could not be read, so it was not checked.', $file);

        continue;
    }

    ++$scanned;

    foreach (OWNER_CONNECTION_SPELLINGS as $spelling) {
        if (!str_contains($contents, $spelling)) {
            continue;
        }

        $violations[] = sprintf(
            '%s names the OWNER connection (`%s`). That connection is the role which OWNS the tenant-owned '
            . 'tables: it can `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` or `DROP POLICY` in one statement, and '
            . 'FORCE does not stop it — FORCE prevents an owner SKIPPING policies, not REMOVING them. It exists '
            . 'ONLY so migrations run as something other than the application credential. Use the default '
            . 'connection; if a genuine migration-time caller needs it, add the path to PERMITTED_PATHS with the '
            . 'argument in the commit message.',
            $file,
            $spelling,
        );
    }
}

// Printed UNCONDITIONALLY and before the verdict, so the meta-suite can prove this looked at something. A gate that
// scanned zero files reports OK indistinguishably from one that scanned the whole tree -- the anti-vacuity lesson
// CLAUDE.md § Gotchas records for `test-gates.sh` reporting 33/33 over a fixture missing its input.
printf("counts — files=%d violations=%d\n", $scanned, count($violations));

if (0 === $scanned) {
    fwrite(STDERR, "no-owner-connection: FAIL — scanned NO files, so this gate asserted nothing.\n"
        . "  Either api/src/ is empty or nothing there is tracked. Both look identical to a clean run unless\n"
        . "  this is checked, which is why it is.\n");

    exit(1);
}

if ([] !== $violations) {
    fwrite(STDERR, "no-owner-connection: FAIL — application code can reach the table-owning role.\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, '  ' . $violation . "\n");
    }

    exit(1);
}

printf("no-owner-connection: OK — %d tracked file(s) under api/src/ name the owner connection nowhere.\n", $scanned);
