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
        ),
        new Post(
            uriTemplate: '/me/company',
            processor: SwitchCompanyProcessor::class,
            security: 'is_granted("ROLE_USER")',
            validationContext: ['groups' => ['switch']],
        ),
    ],
)]
final class WorkingCompanyResource
{
    #[Assert\NotBlank(groups: ['switch'])]
    #[Assert\Uuid(groups: ['switch'])]
    public string $companyId = '';

    #[ApiProperty(writable: false)]
    public ?string $name = null;

    #[ApiProperty(writable: false)]
    public ?string $status = null;

    #[ApiProperty(writable: false)]
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
