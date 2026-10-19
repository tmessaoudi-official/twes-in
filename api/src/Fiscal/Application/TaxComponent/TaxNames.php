<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\TaxComponent;

use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Tenancy\Domain\Model\Company;

/**
 * What a printed document calls each of the company's taxes: a figure carries only the code it was issued under, and
 * the document prints that tax's name in its own language.
 */
final readonly class TaxNames
{
    public function __construct(private TaxComponentRepository $components)
    {
    }

    /** @return array<string, string> by code */
    public function in(Company $company, string $language): array
    {
        $names = [];
        foreach ($this->components->ofCompany($company->getId()) as $component) {
            $names[$component->getCode()] = $component->nameIn($language);
        }

        return $names;
    }
}
