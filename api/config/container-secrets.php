<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

// Loaded by Composer with every autoloader (composer.json, autoload.files), before the runtime reads the environment:
// the compose secrets a deployment mounts become the variables the application reads, in every PHP process.
App\Shared\Infrastructure\Secrets\ContainerSecrets::load();
