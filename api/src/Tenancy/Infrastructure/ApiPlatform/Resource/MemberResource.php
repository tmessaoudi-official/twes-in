<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Tenancy\Application\Member\MemberView;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\State\Processor\ChangeMemberRoleProcessor;
use App\Tenancy\Infrastructure\ApiPlatform\State\Processor\InviteMemberProcessor;
use App\Tenancy\Infrastructure\ApiPlatform\State\Processor\RemoveMemberProcessor;
use App\Tenancy\Infrastructure\ApiPlatform\State\Processor\WithdrawInvitationProcessor;
use App\Tenancy\Infrastructure\ApiPlatform\State\Provider\MemberCollectionProvider;
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
        // Another role for someone already a member, where they are listed: no removing and inviting again.
        new Put(
            uriTemplate: '/companies/{companyId}/members/{userId}/role',
            processor: ChangeMemberRoleProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::ROLE]],
            validationContext: ['groups' => [self::ROLE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/members/{userId}',
            processor: RemoveMemberProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
        // An invitation sent to the wrong address is taken back; sending it again, or at another role, is inviting again.
        new Delete(
            uriTemplate: '/companies/{companyId}/invitations/{invitationId}',
            processor: WithdrawInvitationProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class MemberResource
{
    public const string JOINED = 'joined';
    public const string INVITED = 'invited';
    public const string EXPIRED = 'expired';

    /**
     * Read and write shapes are named explicitly. Without them a write operation answers with only the
     * properties it accepts, so everything this endpoint computes (the identifier, the display name, whether
     * the address was invited rather than added) was missing from its own 201.
     */
    public const string READ = 'member:read';
    public const string WRITE = 'member:write';
    /** Changing a member's role takes the role alone. */
    public const string ROLE = 'member:role';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $userId = null;

    #[Assert\NotBlank(groups: ['add'])]
    #[Assert\Email(groups: ['add'])]
    #[Assert\Length(max: 254, groups: ['add'])]
    #[Groups([self::READ, self::WRITE])]
    public string $email = '';

    /**
     * Which role, by name. There is deliberately no `Assert\Choice` here: the set depends on the company being
     * acted for — its own roles as well as the built-in five — and an attribute is fixed at class level with no
     * request in sight. `InviteToCompany` resolves the name against that company and refuses an unknown one, which
     * is the same 422 with a message that can say which company it looked in.
     */
    #[Assert\NotBlank(groups: ['add', self::ROLE])]
    #[Groups([self::READ, self::WRITE, self::ROLE])]
    public string $role = Role::MEMBER;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $displayName = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $joinedAt = null;

    /**
     * Whether that person is a member, an address invited that has not accepted, or one whose invitation ran out unused;
     * the last two name nobody.
     */
    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => [self::JOINED, self::INVITED, self::EXPIRED]])]
    #[Groups([self::READ])]
    public string $status = self::JOINED;

    /** The invitation an invited row stands for, which the screen withdraws or sends again; null on a member's row. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $invitationId = null;

    /** Until when an invitation's link can be used; null on a member's row. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $expiresAt = null;

    public static function of(MemberView $view): self
    {
        $resource = new self();
        $resource->userId = $view->userId;
        $resource->email = $view->email;
        $resource->displayName = $view->displayName;
        $resource->role = $view->role;
        $resource->joinedAt = $view->joinedAt;
        $resource->status = $view->status;
        $resource->invitationId = $view->invitationId;
        $resource->expiresAt = $view->expiresAt;

        return $resource;
    }
}
