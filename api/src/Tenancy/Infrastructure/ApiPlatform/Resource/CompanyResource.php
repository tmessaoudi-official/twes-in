<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Shared\Infrastructure\Validation\DocumentLanguage;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\State\Processor\CreateCompanyProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** A company as the platform sees it. Only an operator opens one, and it waits for its first owner. */
#[ApiResource(
    shortName: 'Company',
    operations: [
        new Post(
            uriTemplate: '/companies',
            processor: CreateCompanyProcessor::class,
            security: 'is_granted("platform.company.create")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => ['create']],
        ),
    ],
)]
final class CompanyResource
{
    /** What this endpoint answers with, and what it accepts; naming both keeps the 201 complete. */
    public const string READ = 'company:read';
    public const string WRITE = 'company:write';

    // No identifier: this resource is only ever reached through the explicit uriTemplates above, and an
    // identifier property makes API Platform publish an item route we never wrote a provider for.
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: ['create'], payload: ['reason' => 'value_required'])]
    #[Assert\Length(max: 160, groups: ['create'], payload: ['reason' => 'invalid_text', 'params' => ['max' => 160]])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Country(groups: ['create'])]
    #[Groups([self::READ, self::WRITE])]
    public string $countryCode = '';

    /** Left out on create: the currency its country's fiscal preset keeps the books in. */
    #[Assert\NotBlank(allowNull: true, groups: ['create'])]
    #[Assert\Currency(groups: ['create'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $currency = null;

    /** Left out on create: the first language the country's fiscal preset prints in. */
    #[Assert\NotBlank(allowNull: true, groups: ['create'])]
    #[DocumentLanguage(groups: ['create'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $locale = null;

    /** Left out on create: the zone its country's fiscal preset starts a company in. */
    #[Assert\NotBlank(allowNull: true, groups: ['create'])]
    #[Assert\Timezone(groups: ['create'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $timezone = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $status = null;

    public static function of(Company $company): self
    {
        $resource = new self();
        $resource->id = $company->getId()->toRfc4122();
        $resource->name = $company->getName();
        $resource->countryCode = $company->getCountryCode();
        $resource->currency = $company->getCurrency();
        $resource->locale = $company->getLocale();
        $resource->timezone = $company->getTimezone();
        $resource->status = $company->getStatus();

        return $resource;
    }
}
