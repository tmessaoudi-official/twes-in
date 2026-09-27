<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

/*
 * What the production image promises (infra/api/Dockerfile, stage prod), read from inside a running container by
 * the PHP it runs on: prod mode, php.ini-production, Symfony's OPcache values, the kernel's preload list wired and
 * warmed at build, and no dev packages. Each can go quietly: a build argument left at dev, a stage copying the
 * wrong ini, a composer install without --no-dev all still serve every request, only slower or with more exposed.
 *
 * Usage, against the prod compose stack: docker compose -f compose.yaml -f compose.prod.yaml exec -T api php < scripts/gates/production-image.php
 * or: php production-image.php [APP_DIR] (default: the current directory).
 */

$app = rtrim($argv[1] ?? (string) getcwd(), '/');
$problems = [];

$env = $_SERVER['APP_ENV'] ?? getenv('APP_ENV');
if ('prod' !== $env) {
    $problems[] = \sprintf('APP_ENV is "%s", not prod', is_string($env) ? $env : '');
}

$off = static fn (string $key): bool => in_array(strtolower((string) ini_get($key)), ['', '0', 'off', 'false', 'no'], true);
if ('-1' !== ini_get('zend.assertions')) {
    $problems[] = \sprintf('zend.assertions is %s, not -1 (php.ini-production)', var_export(ini_get('zend.assertions'), true));
}
if (!$off('display_errors')) {
    $problems[] = 'display_errors is on (php.ini-production turns it off)';
}
if ('0' !== ini_get('opcache.validate_timestamps')) {
    $problems[] = 'opcache.validate_timestamps is not 0: every request checks every file for a change';
}

// At least Symfony's recommended values (https://symfony.com/doc/current/performance.html).
foreach (['opcache.memory_consumption' => 256, 'opcache.max_accelerated_files' => 32531, 'opcache.interned_strings_buffer' => 32, 'realpath_cache_ttl' => 600] as $key => $least) {
    if ((int) ini_get($key) < $least) {
        $problems[] = \sprintf('%s is %s, below %d', $key, var_export(ini_get($key), true), $least);
    }
}

$preload = (string) ini_get('opcache.preload');
if ($preload !== $app.'/config/preload.php' || !is_file($preload)) {
    $problems[] = \sprintf('opcache.preload is "%s", not %s/config/preload.php', $preload, $app);
}
if ([] === (glob($app.'/var/cache/prod/*.preload.php') ?: [])) {
    $problems[] = 'var/cache/prod holds no preload list: the cache was not warmed at build, so nothing is preloaded';
}

$installed = is_file($app.'/vendor/composer/installed.php') ? require $app.'/vendor/composer/installed.php' : null;
if (!is_array($installed) || false !== ($installed['root']['dev'] ?? null)) {
    $problems[] = 'the dev packages are installed (composer install without --no-dev)';
}

if ([] !== $problems) {
    fwrite(\STDOUT, \sprintf("production-image: FAIL — %d problem(s):\n", count($problems)));
    foreach ($problems as $problem) {
        fwrite(\STDOUT, '  '.$problem."\n");
    }
    exit(1);
}
fwrite(\STDOUT, "production-image: OK — prod mode, production ini, Symfony's OPcache values, preload warmed, no dev packages\n");
