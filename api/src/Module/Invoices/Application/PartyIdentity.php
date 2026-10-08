<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Module\Customers\Domain\Customer;
use App\Tenancy\Application\Company\SellerIdentity;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;

/**
 * Who an invoice names, checked before it takes a number (Code de la TVA art. 18-II, CGI annexe II art. 242 nonies A):
 * the seller's legal name, address and registration numbers; the customer's name, address and, for a business at home,
 * the numbers its preset requires of one. Both are read as they stand at issue: a preset may come to require a number
 * after the customer was saved, and the profile may have been emptied since the last invoice.
 */
final readonly class PartyIdentity
{
    public function __construct(private FiscalPresets $presets)
    {
    }

    /** @throws PartyIdentityMissing */
    public function assertNamed(Company $company, Establishment $establishment, Customer $customer): void
    {
        $preset = $this->presets->get($company->getFiscalPreset());
        $seller = SellerIdentity::missing($company, $preset, $establishment);
        if ([] !== $seller) {
            throw new PartyIdentityMissing(PartyIdentityMissing::SELLER, $seller);
        }

        $profile = $customer->getProfile();
        $missing = [];
        if (null === $profile->billingAddress->line1) {
            $missing[] = 'billingAddressLine1';
        }
        if (null === $profile->billingAddress->city) {
            $missing[] = 'billingCity';
        }
        $refusal = IdentifierRules::refusal($preset, $profile->identifiers, $profile->isDomesticBusiness($company->getCountryCode()) ? IdentifierRules::BUSINESS_CUSTOMER : '');
        if (null !== $refusal) {
            $missing[] = $refusal->field;
        }
        if ([] !== $missing) {
            throw new PartyIdentityMissing(PartyIdentityMissing::CUSTOMER, $missing);
        }
    }
}
