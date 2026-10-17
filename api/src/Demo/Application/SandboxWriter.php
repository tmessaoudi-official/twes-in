<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use App\Demo\Domain\Model\DemoSandbox;

/**
 * Writes a sandbox being built: opens its company and its owner through `OpenSandboxCompany`, then the demo history of
 * its country. Long, and made of many transactions of its own; it never runs inside one.
 */
interface SandboxWriter
{
    public function write(DemoSandbox $sandbox): void;
}
