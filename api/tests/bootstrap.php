<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Under paratest each worker is numbered by TEST_TOKEN: its database is twes_test<token> (doctrine.yaml), its compiled
// container a cache directory of its own (Kernel::getCacheDir), and its app pools, the rate limiters' among them, a
// share directory of its own (APP_SHARE_DIR, var/share otherwise): a limiter one worker clears is never another's.
$token = getenv('TEST_TOKEN');
if (is_string($token) && '' !== $token) {
    // tests/parallel.php hands on the tree's own token (a writer's worktree), which paratest's number follows.
    $base = getenv('TWES_TEST_BASE');
    if (is_string($base) && '' !== $base) {
        $token = $base.'_'.$token;
        $_SERVER['TEST_TOKEN'] = $_ENV['TEST_TOKEN'] = $token;
        putenv('TEST_TOKEN='.$token);
    }
    $_SERVER['APP_CACHE_DIR'] = dirname(__DIR__).'/var/cache/paratest-'.$token;
    $_SERVER['APP_SHARE_DIR'] = dirname(__DIR__).'/var/share/paratest-'.$token;
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// A container compiled without debug is never checked against the source again, so a later run booted one built before
// the code it tested: a debug-off case answered 500 at sign-in in the snapshot gate's worktree, which keeps its caches.
// Its loader goes at every run, and the first debug-off case compiles it from the tree as it stands.
(static function (): void {
    $cache = $_SERVER['APP_CACHE_DIR'] ?? null;
    $directory = (is_string($cache) && '' !== $cache ? $cache : dirname(__DIR__).'/var/cache').'/test';
    if (is_file($directory.'/App_KernelTestContainer.php')) {
        unlink($directory.'/App_KernelTestContainer.php');
    }
})();

// A test that tampers with an issued invoice on purpose (a data check or the chain must notice it) opens the fiscal guard
// through this door, PastFiscalGuard::pastFiscalGuard; FiscalGuardTest empties a table as the owner through the second,
// which the application's role could never do. Both are made here, in this process's test database alone, never by a
// migration, and each refuses to run in any database not named for the tests (scripts/gates/fiscal-test-door.sh).
(static function (): void {
    // make stress's preparation reads this file for its directories before it makes its database; the racers' own run
    // comes back here once it exists.
    if (defined('TWES_TEST_DATABASE_MADE_AFTER_BOOTSTRAP')) {
        return;
    }
    $url = $_SERVER['DATABASE_MIGRATION_URL'] ?? null;
    if (!is_string($url) || '' === $url) {
        throw new LogicException('DATABASE_MIGRATION_URL names no owner to make the fiscal guard\'s test door with.');
    }
    $owner = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
    // doctrine.yaml's suffix: .env.test.local's token (a worktree's) reaches $_SERVER through Dotenv, never getenv().
    $token = $_SERVER['TEST_TOKEN'] ?? '';
    $owner['dbname'] = ($owner['dbname'] ?? 'twes').'_test'.(is_string($token) ? $token : '');
    $connection = DriverManager::getConnection($owner);
    // The race tests' racers each read this file against one database at once, and two processes replacing one function
    // collide (« tuple concurrently updated »): one at a time, under a lock the transaction lets go.
    $connection->beginTransaction();
    $connection->executeStatement("SELECT pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtext('fiscal_guard_test_doors'))");
    $connection->executeStatement(<<<'SQL'
        CREATE OR REPLACE FUNCTION public.fiscal_guard_test_release(open boolean) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
            AS $$
        BEGIN
            IF pg_catalog.current_database() !~ '^twes_test' THEN
                RAISE EXCEPTION 'the fiscal guard''s test door opens in a test database alone, not in %', pg_catalog.current_database();
            END IF;
            IF open THEN
                INSERT INTO public.fiscal_release (transaction_id, company_id, reason, at)
                    VALUES (pg_catalog.pg_current_xact_id(), NULL, 'test', localtimestamp(0));
            ELSE
                DELETE FROM public.fiscal_release WHERE transaction_id OPERATOR(pg_catalog.=) pg_catalog.pg_current_xact_id() AND company_id IS NULL;
            END IF;
        END
        $$
        SQL);
    $connection->executeStatement(<<<'SQL'
        CREATE OR REPLACE FUNCTION public.fiscal_guard_test_truncate(target regclass) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
            AS $$
        BEGIN
            IF pg_catalog.current_database() !~ '^twes_test' THEN
                RAISE EXCEPTION 'the fiscal guard''s test door opens in a test database alone, not in %', pg_catalog.current_database();
            END IF;
            EXECUTE pg_catalog.format('TRUNCATE %s CASCADE', target);
        END
        $$
        SQL);
    $connection->commit();
    $connection->close();
})();
