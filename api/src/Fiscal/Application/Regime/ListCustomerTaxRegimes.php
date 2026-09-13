<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Regime;

use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Tenancy\Domain\Company;

/** The regimes a company's customers may be under: those of the company's fiscal preset. */
final readonly class ListCustomerTaxRegimes
{
    public function __construct(private CustomerTaxRegimeRepository $regimes)
    {
    }

    /** @return list<CustomerTaxRegime> */
    public function for(Company $company): array
    {
        return $this->regimes->ofPreset($company->getFiscalPreset());
    }
}
