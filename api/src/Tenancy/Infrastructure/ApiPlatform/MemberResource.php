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
use Symfony\Component\Serializer\Attribute\Groups;
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
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/members',
            processor: InviteMemberProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
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
    public const string JOINED = 'joined';
    public const string INVITED = 'invited';

    /**
     * Read and write shapes are named explicitly. Without them a write operation answers with only the
     * properties it accepts, so everything this endpoint computes (the identifier, the display name, whether
     * the address was invited rather than added) was missing from its own 201.
     */
    public const string READ = 'member:read';
    public const string WRITE = 'member:write';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $userId = null;

    #[Assert\NotBlank(groups: ['add'])]
    #[Assert\Email(groups: ['add'])]
    #[Assert\Length(max: 254, groups: ['add'])]
    #[Groups([self::READ, self::WRITE])]
    public string $email = '';

    #[Assert\NotBlank(groups: ['add'])]
    #[Assert\Choice(choices: [Role::OWNER, Role::ADMIN, Role::MEMBER], groups: ['add'])]
    #[Groups([self::READ, self::WRITE])]
    public string $role = Role::MEMBER;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $displayName = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $joinedAt = null;

    /** Whether that address is now a member, or has been sent an invitation because it has no account yet. */
    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => [self::JOINED, self::INVITED]])]
    #[Groups([self::READ])]
    public string $status = self::JOINED;

    public static function of(MemberView $view): self
    {
        $resource = new self();
        $resource->userId = $view->userId;
        $resource->email = $view->email;
        $resource->displayName = $view->displayName;
        $resource->role = $view->role;
        $resource->joinedAt = $view->joinedAt;
        $resource->status = self::JOINED;

        return $resource;
    }
}
