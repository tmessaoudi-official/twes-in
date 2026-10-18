<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Tenancy;

use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Tenancy\Application\Company\DemoCompanies;
use Symfony\Component\Uid\Uuid;

/** A demo company is a sandbox's, from the moment its build opens it, so what the build prints says so too. */
final readonly class SandboxCompanies implements DemoCompanies
{
    public function __construct(private DemoSandboxRepository $sandboxes)
    {
    }

    public function isDemo(Uuid $companyId): bool
    {
        return null !== $this->sandboxes->ofCompany($companyId);
    }
}
