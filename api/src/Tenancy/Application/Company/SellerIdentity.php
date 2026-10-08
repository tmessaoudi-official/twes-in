<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Fiscal\Application\Preset\FiscalPreset;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;

/**
 * What every invoice must say of its seller (Code de la TVA art. 18-II, CGI annexe II art. 242 nonies A): its legal name,
 * the address it prints, and each registration number its preset requires of a company, in the shape it expects.
 * « Premiers pas » asks for the same thing, so its step is done exactly when issuing stops refusing for the seller.
 */
final class SellerIdentity
{
    /**
     * The fields missing or refused, as the company profile names them; empty when the seller can be printed.
     *
     * @param Establishment|null $establishment the one issuing, whose own address is printed when it has one
     *
     * @return list<string>
     */
    public static function missing(Company $company, FiscalPreset $preset, ?Establishment $establishment = null): array
    {
        $profile = $company->getProfile();
        $missing = null === $profile->legalName ? ['legalName'] : [];
        // The establishment's address is printed whole when it has a first line, as SellerSnapshot prints it.
        $city = null !== $establishment?->getAddressLine1() ? $establishment->getCity() : $profile->city;
        if (null === $establishment?->getAddressLine1() && null === $profile->addressLine1) {
            $missing[] = 'addressLine1';
        }
        if (null === $city || '' === trim($city)) {
            $missing[] = 'city';
        }
        $refusal = IdentifierRules::refusal($preset, $profile->identifiers, IdentifierRules::COMPANY);
        if (null !== $refusal) {
            $missing[] = $refusal->field;
        }

        return $missing;
    }
}
