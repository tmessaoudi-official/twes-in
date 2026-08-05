<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Twes\Kernel;

/*
 * THE CONVENTIONAL SYMFONY FRONT CONTROLLER: `vendor/autoload_runtime.php` plus a closure returning the kernel.
 *
 * This file previously hand-rolled the bootstrap -- `Dotenv::bootEnv()`, `Request::createFromGlobals()`,
 * `$kernel->handle()`, `send()`, `terminate()` -- on the stated grounds that `symfony/runtime` is a Composer
 * PLUGIN and plugins are disabled in this project's development container, so the generated
 * `vendor/autoload_runtime.php` would not exist. THAT CLAIM WAS FALSE. The file is present and
 * `composer dump-autoload` regenerates it [Verified: deleted it, re-ran dump-autoload, it came back; and
 * `Symfony\Component\Runtime\SymfonyRuntime` loads]. The plugin is allow-listed in `composer.json` and runs.
 *
 * The correction matters beyond tidiness, and this is the load-bearing part: `autoload_runtime.php` is the ONLY
 * mechanism by which `APP_RUNTIME` selects a different runtime. FrankenPHP's worker mode needs
 * `APP_RUNTIME=Runtime\FrankenPhpSymfony\Runtime`, and so do RoadRunner and Swoole. A hand-rolled bootstrap does
 * not merely deviate from convention -- it makes worker mode UNREACHABLE. `infra/api/Caddyfile` records the THREE
 * preconditions still owed before that is switched on -- the binding one being the client portal's own
 * `random_bytes(32)` token (Wave 10), because `UuidV7` seeds per PROCESS and a worker's recoverable seed would
 * span TENANTS. `scripts/gates/worker-mode-blocked.sh` refuses every route to it. CLAUDE.md Gotchas 2026-08-05.
 *
 * The runtime loads the `.env` cascade itself (`.env` -> `.env.local` -> `.env.$APP_ENV` -> `.env.$APP_ENV.local`)
 * and passes the resolved values in as `$context`, which is why there is no `Dotenv` call here.
 */
require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
