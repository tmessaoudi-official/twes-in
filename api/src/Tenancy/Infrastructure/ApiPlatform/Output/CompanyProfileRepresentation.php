<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\Output;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Shared\Application\StoredContacts;
use App\Shared\Infrastructure\Intl\GroupedPhones;
use App\Tenancy\Application\Company\CompanyLogo;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyProfileResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A company's profile as the endpoint answers it, with its preset's identifiers and regimes in the reader's language. */
final readonly class CompanyProfileRepresentation
{
    public function __construct(
        private CompanyGuard $guard,
        private FiscalPresets $presets,
        private TranslatorInterface $translator,
        private CompanyLogo $logo,
        private StoredContacts $contacts,
    ) {
    }

    public function of(Company $company): CompanyProfileResource
    {
        $preset = $this->presets->get($company->getFiscalPreset());
        $profile = $company->getProfile();

        $resource = new CompanyProfileResource();
        $resource->name = $company->getName();
        $resource->countryCode = $company->getCountryCode();
        $resource->writable = $this->guard->may($company, CompanyProfileResource::WRITE_PERMISSION);
        $resource->logoVersion = $this->logo->current($company)?->getFile()->getId()->toRfc4122();
        $resource->version = $company->getVersion();
        $resource->legalName = $profile->legalName;
        $resource->legalForm = $profile->legalForm;
        $resource->identifiers = $profile->identifiers;
        $resource->addressLine1 = $profile->addressLine1;
        $resource->addressLine2 = $profile->addressLine2;
        $resource->postalCode = $profile->postalCode;
        $resource->city = $profile->city;
        $resource->email = $profile->email;
        $resource->phone = GroupedPhones::of($profile->phone);
        $resource->website = $profile->website;
        $resource->iban = $profile->iban;
        $resource->bic = $profile->bic;
        $resource->vatRegime = $profile->vatRegime;
        $resource->invoiceFooterText = $profile->invoiceFooterText;
        $resource->latePenaltyText = $profile->latePenaltyText;
        $resource->vatOnDebits = $profile->vatOnDebits;
        $resource->offersVatOnDebits = $preset->invoiceFields->offersVatOnDebits();
        $resource->contactsToCheck = array_keys($this->contacts->refused($profile->email, $profile->phone, $company->getCountryCode()));
        foreach ($preset->identifiers as $identifier) {
            $resource->identifierFields[] = new CompanyIdentifierField(
                $identifier->key,
                $this->translator->trans($identifier->labelKey, [], 'fiscal'),
                $identifier->patternFor(IdentifierRules::COMPANY),
                $identifier->isRequiredOf(IdentifierRules::COMPANY),
                null === $identifier->hintKey ? null : $this->translator->trans($identifier->hintKey, ['%example%' => $identifier->example], 'fiscal'),
            );
        }
        foreach ($preset->companyVatRegimes as $regime) {
            $resource->vatRegimes[] = new CompanyVatRegimeOption($regime->code, $this->translator->trans($regime->labelKey, [], 'fiscal'));
        }

        return $resource;
    }
}
