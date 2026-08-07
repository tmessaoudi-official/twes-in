<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$composerAutoloader = __DIR__ . '/../vendor/autoload.php';

if (is_file($composerAutoloader)) {
    require $composerAutoloader;

    /*
     * LOAD `.env`, WHICH THIS FILE DID NOT DO UNTIL 2026-08-05 — and the omission made the FUNCTIONAL SUITE
     * DEPEND ON THE AMBIENT SHELL.
     *
     * `phpunit.xml` declares the variables the tests were known to need (`APP_ENV`, `APP_SECRET`, `DATABASE_URL`,
     * the tenancy roles...), but `config/packages/framework.yaml` also reads `%env(TRUSTED_PROXIES)%` and
     * `%env(DEFAULT_LOCALE)%`, and NEITHER was declared there. With no `.env` loaded, booting the kernel raised
     * `EnvNotFoundException: Environment variable not found: "TRUSTED_PROXIES"` — so the nine `HttpSurfaceTest`
     * cases passed only in a shell that happened to export them, and failed in a clean one. [Verified: 9 errors
     * from a clean environment; setting `TRUSTED_PROXIES` alone moved the failure on to `DEFAULT_LOCALE`, which is
     * what shows the problem was the missing loader rather than one missing variable.]
     *
     * A suite whose result depends on the shell that launched it is the same shape this project has recorded
     * repeatedly: a check that appears to pass for a reason unrelated to what it asserts.
     *
     * `bootEnv` IS THE ECOSYSTEM'S ANSWER, and is what Symfony's own `tests/bootstrap.php` does — so this is
     * adopting the convention rather than inventing a fix. Two properties make it safe here:
     *
     *   - IT DOES NOT OVERRIDE AN ALREADY-SET VARIABLE. PHPUnit applies `<php><env>` before this file runs, so
     *     every value in `phpunit.xml` still wins and `.env` only fills the gaps. `APP_ENV=test` therefore stays
     *     `test` even though `.env` says `dev` — and `bootEnv` reads that to decide it should also load
     *     `.env.test`, which is exactly the layering we want.
     *   - IT IS GUARDED BY `class_exists`, so the vendor-less fallback path below is unaffected.
     */
    if (class_exists(Dotenv::class)) {
        new Dotenv()->bootEnv(__DIR__ . '/../.env');
    }

    /*
     * DISCARD THE COMPILED TEST CONTAINER, BECAUSE A STALE ONE MAKES THE SUITE REPORT GREEN OVER A MISSING
     * TENANCY CONTROL. This is the most serious defect the second MAXIMAL round found, and it is a defect in the
     * suite rather than in the application.
     *
     * `phpunit.xml` sets `APP_DEBUG=0` — correctly, because several cases assert non-debug behaviour (a
     * propagating hydration failure must answer a bare 500 with no message and no trace; with `APP_DEBUG=1` it
     * exposes both). But a kernel booted with `$debug = false` performs NO freshness check on
     * `var/cache/test/…Container.php`: it loads it and never asks whether `config/services.yaml` has changed
     * since. So a change to service wiring is invisible to every kernel-booting test until somebody clears the
     * cache by hand, which nothing in the suite, in `composer gate` or in the Makefile does.
     *
     * WHAT THAT COSTS, measured rather than argued. Exactly ONE test of 949 detects the absence of
     * `TenantBindingMiddleware` — the control whose missing call site was round 1's headline P0, without which
     * every tenant-owned read returns nothing and every write is refused. Delete its registration from
     * `config/services.yaml`, leave the cache warm, and:
     *
     *     $ php tools/bin/phpunit-12.phar --filter TenantBindingWiringTest
     *     OK (4 tests, 27 assertions)
     *
     * The same tree with the cache cleared reports `Failures: 1`. [Verified: those two runs differed in nothing
     * but the cache.] `CLAUDE.md` § Gotchas records four separate controls that silently did not run; this is the
     * first one where the silence was produced by a build artefact rather than by a skip.
     *
     * `TenantBindingWiringTest`'s own docblock already warned about the HARMLESS direction of this — a stale cache
     * making a correct tree fail — and stopped there. The false-POSITIVE direction is the one that matters, and
     * noticing only the direction that inconvenienced me is the honest account of how it survived.
     *
     * TWO ALTERNATIVES, both rejected on merit. `APP_DEBUG=1` would restore freshness checking and change the
     * behaviour the suite asserts, which trades a real assertion for a build concern. Clearing the cache in a
     * `pre-gate:test` Composer script would cover `composer gate` and miss every other invocation — the bare
     * `phpunit` command in `CLAUDE.md` § "Quality gate", `make test`, and an IDE runner — which is precisely the
     * second-copy-of-a-command divergence this project keeps recording. Here it applies however PHPUnit is
     * launched, because PHPUnit itself is what runs this file.
     *
     * The cost is ONE container rebuild per suite run, not per test: the functional suite takes 3.6 s cold against
     * 1.4 s warm [measured], on a full run of 40 s. Only `test` is removed, never `dev` — a developer's own cache
     * is not this file's business.
     */
    $compiled = __DIR__ . '/../var/cache/test';

    if (is_dir($compiled)) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($compiled, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($compiled);
    }

    return;
}

spl_autoload_register(static function (string $class): void {
    /** @var array<string, string> $prefixes PSR-4 prefix => absolute base directory */
    $prefixes = [
        'Twes\\Tests\\' => __DIR__,
        'Twes\\' => __DIR__ . '/../src',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;

            return;
        }
    }
});
