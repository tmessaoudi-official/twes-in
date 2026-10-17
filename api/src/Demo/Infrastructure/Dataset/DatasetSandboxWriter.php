<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Dataset;

use App\Demo\Application\OpenSandboxCompany;
use App\Demo\Application\SandboxWriter;
use App\Demo\Domain\Model\DemoSandbox;
use App\Tenancy\Application\Company\NewCompany;

/** A sandbox gets the catalogue company of its country, opened by `OpenSandboxCompany` and written by `DemoDataset`. */
final readonly class DatasetSandboxWriter implements SandboxWriter
{
    public function __construct(
        private DemoDataset $dataset,
        private OpenSandboxCompany $open,
    ) {
    }

    public function write(DemoSandbox $sandbox): void
    {
        $sandboxId = $sandbox->getId();
        $this->dataset->write(DemoCatalogue::of($sandbox->getCountry()), function (DemoCompany $demo) use ($sandboxId): OpenedCompany {
            [$companyId, $ownerId] = $this->open->handle($sandboxId, new NewCompany($demo->name, $demo->country, $demo->currency, 'fr', $demo->timezone));

            return new OpenedCompany($companyId, $ownerId);
        });
    }
}
