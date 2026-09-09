<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Tenancy\Application\Company\MemberView;
use App\Tenancy\Domain\Role;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Somebody's place in one company. The company is always in the path, and the caller's right to act on that
 * company is checked by CompanyGuard, which answers 404 for a company that is none of their business.
 */
#[ApiResource(
    shortName: 'Member',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/members',
            provider: MemberCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/members',
            processor: AddMemberProcessor::class,
            security: 'is_granted("ROLE_USER")',
            validationContext: ['groups' => ['add']],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/members/{userId}',
            processor: RemoveMemberProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class MemberResource
{
    #[ApiProperty(writable: false)]
    public ?string $userId = null;

    #[Assert\NotBlank(groups: ['add'])]
    #[Assert\Email(groups: ['add'])]
    #[Assert\Length(max: 254, groups: ['add'])]
    public string $email = '';

    #[Assert\NotBlank(groups: ['add'])]
    #[Assert\Choice(choices: [Role::OWNER, Role::ADMIN, Role::MEMBER], groups: ['add'])]
    public string $role = Role::MEMBER;

    #[ApiProperty(writable: false)]
    public ?string $displayName = null;

    #[ApiProperty(writable: false)]
    public ?string $joinedAt = null;

    public static function of(MemberView $view): self
    {
        $resource = new self();
        $resource->userId = $view->userId;
        $resource->email = $view->email;
        $resource->displayName = $view->displayName;
        $resource->role = $view->role;
        $resource->joinedAt = $view->joinedAt;

        return $resource;
    }
}
