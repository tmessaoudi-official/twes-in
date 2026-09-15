<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Tenancy\Domain\Company;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Whether the company requires a second factor of its members (docs/SPEC.md § 3 Auth). Read with `company.read` and
 * changed with `company.settings`, like the profile, but kept apart from it: the profile is what documents print.
 */
#[ApiResource(
    shortName: 'CompanySecurity',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/security',
            provider: CompanySecurityProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/security',
            processor: RequireSecondFactorProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class CompanySecurityResource
{
    public const string READ = 'company_security:read';
    public const string WRITE = 'company_security:write';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ, self::WRITE])]
    public bool $mfaRequired = false;

    /** Whether the caller may change it: what the page offers, the PUT enforces. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $writable = false;

    public static function of(Company $company, bool $writable): self
    {
        $resource = new self();
        $resource->mfaRequired = $company->isMfaRequired();
        $resource->writable = $writable;

        return $resource;
    }
}
