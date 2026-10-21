<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Module\Customers\Domain\ValueObject\CustomerCheckScope;
use App\Tenancy\Domain\Model\Company;

/** What « À vérifier » asks of a company's customers, read from its preset: data, never a country written in code. */
final readonly class CustomerCheckScopes
{
    public function __construct(private FiscalPresets $presets)
    {
    }

    public function of(Company $company): CustomerCheckScope
    {
        return new CustomerCheckScope(
            $company->getCountryCode(),
            IdentifierRules::requiredOf($this->presets->get($company->getFiscalPreset()), IdentifierRules::BUSINESS_CUSTOMER),
        );
    }
}
