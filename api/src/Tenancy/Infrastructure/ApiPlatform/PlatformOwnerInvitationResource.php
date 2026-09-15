<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Tenancy\Domain\Role;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An operator invites a company's owner from the platform (docs/SPEC.md § 7, 2026-09-15, C7): the first owner of a company
 * they opened, or a new one for a company whose owners are gone. The operator never becomes a member of it; the
 * invitation is the one every member gets, and accepting it opens a pending company.
 */
#[ApiResource(
    shortName: 'PlatformOwnerInvitation',
    operations: [
        new Post(
            uriTemplate: '/platform/companies/{companyId}/owners',
            name: 'platform_company_owners',
            processor: InviteOwnerProcessor::class,
            security: 'is_granted("platform.company.create")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => ['invite']],
        ),
    ],
)]
final class PlatformOwnerInvitationResource
{
    public const string READ = 'platform_owner_invitation:read';
    public const string WRITE = 'platform_owner_invitation:write';

    #[ApiProperty(identifier: false)]
    #[Assert\NotBlank(groups: ['invite'])]
    #[Assert\Email(groups: ['invite'])]
    #[Assert\Length(max: 254, groups: ['invite'])]
    #[Groups([self::READ, self::WRITE])]
    public string $email = '';

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => [Role::OWNER]])]
    #[Groups([self::READ])]
    public string $role = Role::OWNER;

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => [MemberResource::INVITED]])]
    #[Groups([self::READ])]
    public string $status = MemberResource::INVITED;
}
