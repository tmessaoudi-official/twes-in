<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Tenancy\Application\Company\PlatformCompanyView;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Every company, as the platform's operators review it: the ones waiting for approval first of all
 * (?status=pending), and the two decisions on one. Only an operator reaches any of it.
 */
#[ApiResource(
    shortName: 'PlatformCompany',
    operations: [
        new GetCollection(
            uriTemplate: '/platform/companies',
            name: 'platform_companies',
            provider: PlatformCompanyCollectionProvider::class,
            security: 'is_granted("platform.company.approve")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/platform/companies/{companyId}/approve',
            name: self::APPROVE,
            status: 200,
            input: false,
            processor: DecideCompanyApprovalProcessor::class,
            security: 'is_granted("platform.company.approve")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/platform/companies/{companyId}/reject',
            name: self::REJECT,
            status: 200,
            input: false,
            processor: DecideCompanyApprovalProcessor::class,
            security: 'is_granted("platform.company.approve")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class PlatformCompanyResource
{
    public const string READ = 'platform_company:read';
    public const string APPROVE = 'platform_company_approve';
    public const string REJECT = 'platform_company_reject';

    // No identifier: reached only through the uriTemplates above (see CompanyResource).
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $name = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $countryCode = '';

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['pending', 'active', 'suspended']])]
    #[Groups([self::READ])]
    public string $status = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $createdAt = '';

    /** @var list<string> the owners' addresses */
    #[ApiProperty(writable: false, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public array $owners = [];

    public static function of(PlatformCompanyView $view): self
    {
        $resource = new self();
        $resource->id = $view->id;
        $resource->name = $view->name;
        $resource->countryCode = $view->countryCode;
        $resource->status = $view->status;
        $resource->createdAt = $view->createdAt;
        $resource->owners = $view->owners;

        return $resource;
    }
}
