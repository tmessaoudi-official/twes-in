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
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The company's closed period: the last day on or before which no document is dated. Read with `company.read`, closed
 * further with `company.settings`, as the profile and the security are; a closed period never opens again.
 */
#[ApiResource(
    shortName: 'CompanyClosing',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/closing',
            provider: CompanyClosingProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/closing',
            processor: ClosePeriodProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class CompanyClosingResource
{
    public const string READ = 'company_closing:read';
    public const string WRITE = 'company_closing:write';

    /** The last closed day, YYYY-MM-DD; null while nothing is closed. Sent to close through a later day than today's. */
    #[ApiProperty(identifier: false, schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $closedThrough = null;

    /** Whether the caller may close it further: what the page offers, the PUT enforces. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $writable = false;

    public static function of(Company $company, bool $writable): self
    {
        $resource = new self();
        $resource->closedThrough = $company->getClosedThrough()?->format('Y-m-d');
        $resource->writable = $writable;

        return $resource;
    }
}
