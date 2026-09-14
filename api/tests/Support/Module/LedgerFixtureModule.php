<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support\Module;

use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/**
 * A module the test environment alone declares, needing customers: no real module depends on another before G6, and
 * the dependency refusals are exercised through the API with it. Outside src/Module/, it owns no resource.
 */
final readonly class LedgerFixtureModule implements DeclaresModule
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('fixture_ledger', 'modules.fixture_ledger', ['customers']);
    }
}
