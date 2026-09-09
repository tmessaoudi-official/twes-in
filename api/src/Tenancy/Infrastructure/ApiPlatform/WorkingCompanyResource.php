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
use App\Tenancy\Application\Company\CompanySummary;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The companies the signed-in user may work in, and the one they are working in now. Switching is a write
 * because it changes the session, which decides what every later request may read.
 */
#[ApiResource(
    shortName: 'WorkingCompany',
    operations: [
        new GetCollection(
            uriTemplate: '/me/companies',
            provider: WorkingCompanyProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/me/company',
            processor: SwitchCompanyProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => ['switch']],
        ),
    ],
)]
final class WorkingCompanyResource
{
    public const string READ = 'working_company:read';
    public const string WRITE = 'working_company:write';

    #[Assert\NotBlank(groups: ['switch'])]
    #[Assert\Uuid(groups: ['switch'])]
    #[Groups([self::READ, self::WRITE])]
    public string $companyId = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $name = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $status = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $role = null;

    public static function of(CompanySummary $summary): self
    {
        $resource = new self();
        $resource->companyId = $summary->companyId;
        $resource->name = $summary->name;
        $resource->status = $summary->status;
        $resource->role = $summary->role;

        return $resource;
    }
}
