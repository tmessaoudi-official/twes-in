<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Identity\Domain\UserRepository;
use App\Tenancy\Domain\Company;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A company's profile as the endpoint answers it, with its preset's identifiers and regimes in the reader's language. */
final readonly class CompanyProfileRepresentation
{
    public function __construct(
        private CompanyGuard $guard,
        private FiscalPresets $presets,
        private UserRepository $users,
        private TranslatorInterface $translator,
    ) {
    }

    public function of(Company $company): CompanyProfileResource
    {
        $preset = $this->presets->get($company->getFiscalPreset());
        $locale = $this->users->ofId($this->guard->account()->getId())?->getLocale() ?? $company->getLocale();
        $profile = $company->getProfile();

        $resource = new CompanyProfileResource();
        $resource->name = $company->getName();
        $resource->countryCode = $company->getCountryCode();
        $resource->writable = $this->guard->may($company, CompanyProfileResource::WRITE_PERMISSION);
        $resource->legalName = $profile->legalName;
        $resource->legalForm = $profile->legalForm;
        $resource->identifiers = $profile->identifiers;
        $resource->addressLine1 = $profile->addressLine1;
        $resource->addressLine2 = $profile->addressLine2;
        $resource->postalCode = $profile->postalCode;
        $resource->city = $profile->city;
        $resource->email = $profile->email;
        $resource->phone = $profile->phone;
        $resource->website = $profile->website;
        $resource->iban = $profile->iban;
        $resource->bic = $profile->bic;
        $resource->vatRegime = $profile->vatRegime;
        $resource->invoiceFooterText = $profile->invoiceFooterText;
        $resource->latePenaltyText = $profile->latePenaltyText;
        foreach ($preset->identifiers as $identifier) {
            $resource->identifierFields[] = new CompanyIdentifierField(
                $identifier->key,
                $this->translator->trans($identifier->labelKey, [], 'fiscal', $locale),
                $identifier->pattern,
                \in_array('company', $identifier->requiredFor, true),
            );
        }
        foreach ($preset->companyVatRegimes as $regime) {
            $resource->vatRegimes[] = new CompanyVatRegimeOption($regime->code, $this->translator->trans($regime->labelKey, [], 'fiscal', $locale));
        }

        return $resource;
    }
}
