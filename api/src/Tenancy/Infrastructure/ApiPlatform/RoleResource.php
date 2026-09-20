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
use ApiPlatform\Metadata\Put;
use App\Tenancy\Application\Role\RoleView;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A role a company may give its members (docs/SPEC.md § 7, 2026-09-20 11:30, row 104). The three built-in roles are
 * the release's and answer `builtIn: true`, read-only; everything else the company made for itself.
 *
 * Behind company.settings, as the permission catalogue is: changing what a role may do is a settings act, and a
 * company none of the caller's business answers 404 rather than 403 — the guard's rule throughout.
 */
#[ApiResource(
    shortName: 'Role',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/roles',
            provider: RoleCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/roles',
            processor: CreateRoleProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/roles/{roleId}',
            processor: ReviseRoleProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/roles/{roleId}',
            processor: DeleteRoleProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class RoleResource
{
    public const string READ = 'role:read';
    public const string WRITE = 'role:write';

    /**
     * Why a write was refused, as a stable token the refusal's `detail` begins with — the same shape
     * `CompanyGuard::SUBSCRIPTION_*` already uses. Three of these answer one status (422), and the screen has three
     * different things to say about them; matching on the English sentence instead would break the day it is
     * reworded, and it says nothing at all once the API is translated.
     */
    public const string BUILT_IN = 'role_built_in';
    public const string IN_USE = 'role_in_use';
    public const string UNKNOWN_PERMISSION = 'role_unknown_permission';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: 64, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    /**
     * The permissions ticked in the matrix. The list is what the role holds afterwards, not what to add: the
     * screen sends the whole set, so unticking is the same request as ticking.
     *
     * `Assert\Type('list')` is not decoration — a `@var list<string>` refuses nothing, so without it a JSON object
     * is denormalized with its keys and stored as one (a lesson this project has already paid for once).
     *
     * @var list<string>
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 128)], groups: [self::WRITE])]
    #[ApiProperty(required: true, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ, self::WRITE])]
    public array $permissions = [];

    /** Whether the release defines it. A built-in role is listed so the screen can show it, and refuses every write. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $builtIn = false;

    /**
     * Whether the role holds everything, now and in later releases. Only the built-in owner does; it is answered
     * separately rather than as a permission because it is never a row to tick, and the screen draws it as
     * "everything" rather than as every box checked.
     */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $wildcard = false;

    /** How many members hold it — what the screen says before it offers to delete anything. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $memberCount = 0;

    public static function of(RoleView $view): self
    {
        $resource = new self();
        $resource->id = $view->id;
        $resource->name = $view->name;
        $resource->builtIn = $view->builtIn;
        $resource->permissions = $view->permissions;
        $resource->wildcard = $view->wildcard;
        $resource->memberCount = $view->memberCount;

        return $resource;
    }
}
