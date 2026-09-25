<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Société à l'ouverture » (docs/SPEC.md § 7, 2026-09-25 09:03): the company every sign-in of this person opens, or
 * null for the one they last worked in. The switcher's list says which one is pinned.
 */
#[ApiResource(
    shortName: 'CompanyAtSignIn',
    operations: [
        new Put(
            uriTemplate: '/me/company-at-sign-in',
            processor: PinCompanyAtSignInProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::WRITE]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class CompanyAtSignInResource
{
    public const string WRITE = 'company_at_sign_in:write';

    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $companyId = null;
}
