<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\FirstSteps;

use App\FirstSteps\Application\DeclaresFirstStep;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Tenancy\Domain\Company;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyProfileResource;

/**
 * « Profil et matricule »: what every document prints about the company — its legal name, its address, and each
 * registration number its fiscal preset requires of a company, in the shape the preset expects.
 */
final readonly class CompanyProfileStep implements DeclaresFirstStep
{
    public function __construct(private FiscalPresets $presets)
    {
    }

    public function key(): string
    {
        return 'company.profile';
    }

    public function position(): int
    {
        return 10;
    }

    public function module(): ?string
    {
        return null;
    }

    public function permission(): string
    {
        return CompanyProfileResource::WRITE_PERMISSION;
    }

    public function isDone(Company $company): bool
    {
        $profile = $company->getProfile();
        if (null === $profile->legalName || null === $profile->addressLine1 || null === $profile->city) {
            return false;
        }

        return null === IdentifierRules::refusal($this->presets->get($company->getFiscalPreset()), $profile->identifiers, IdentifierRules::COMPANY);
    }
}
