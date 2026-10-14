<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Under paratest each worker is numbered by TEST_TOKEN: its database is twes_test<token> (doctrine.yaml), its compiled
// container a cache directory of its own (Kernel::getCacheDir), and its app pools, the rate limiters' among them, a
// share directory of its own (APP_SHARE_DIR, var/share otherwise): a limiter one worker clears is never another's.
$token = getenv('TEST_TOKEN');
if (is_string($token) && '' !== $token) {
    $_SERVER['APP_CACHE_DIR'] = dirname(__DIR__).'/var/cache/paratest-'.$token;
    $_SERVER['APP_SHARE_DIR'] = dirname(__DIR__).'/var/share/paratest-'.$token;
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
