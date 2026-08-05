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
