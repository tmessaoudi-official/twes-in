<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Module\Customers\Domain\Customer;
use App\Tenancy\Domain\Company;

/**
 * The legal mentions an invoice to a customer prints (docs/SPEC.md § 7, 2026-09-14): its customer regime's, its company
 * VAT regime's and its preset's, each once, as translation keys. Issuing keeps them; a draft previews them as they stand.
 */
final readonly class InvoiceMentions
{
    public function __construct(private FiscalPresets $presets, private ExcludedTaxFamilies $regimes)
    {
    }

    /** @return list<string> */
    public function keys(Company $company, Customer $customer): array
    {
        $preset = $this->presets->get($company->getFiscalPreset());
        $companyRegime = $this->regimes->companyRegime($company);

        return array_values(array_unique(array_filter(
            [$customer->getTaxRegime()->getMentionKey(), $companyRegime?->mentionKey, ...$preset->invoiceMentions],
            static fn (?string $key): bool => null !== $key,
        )));
    }
}
