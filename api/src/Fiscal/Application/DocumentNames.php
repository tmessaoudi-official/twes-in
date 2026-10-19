<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application;

use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\ValueObject\PrintedNames;
use App\Tenancy\Domain\Model\Company;

/**
 * What a document calls its units and the company's taxes today, in its own language: what a draft prints, and what an
 * issued document keeps. A figure carries only the code it was issued under, and the document names it from here.
 */
final readonly class DocumentNames
{
    public function __construct(private TaxComponentRepository $components)
    {
    }

    /** @param iterable<Unit> $units the units on the document's lines */
    public function now(Company $company, string $language, iterable $units): PrintedNames
    {
        return PrintedNames::in($language, $units, $this->components->ofCompany($company->getId()));
    }
}
