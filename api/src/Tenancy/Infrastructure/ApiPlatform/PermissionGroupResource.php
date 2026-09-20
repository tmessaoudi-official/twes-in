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
use App\Tenancy\Application\Permission\PermissionGroup;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Every permission a role of this company may be given, grouped as the roles screen shows them (docs/SPEC.md § 7,
 * 2026-09-20 11:30, row 104). Read with company.settings, because it is only of use to someone editing roles.
 *
 * It is the same answer for every company: what the product can do does not depend on who is asking. It is still
 * addressed under a company, because that is where the person editing roles is working and the permission that
 * guards it is a company permission.
 */
#[ApiResource(
    shortName: 'PermissionGroup',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/permission-groups',
            provider: PermissionGroupCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class PermissionGroupResource
{
    public const string READ = 'permission_group:read';

    /** The module's key, or the name of a part of the product that is not a module. */
    #[ApiProperty(identifier: false, writable: false, required: true)]
    #[Groups([self::READ])]
    public string $key = '';

    /** The translation key the screen shows as the heading. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public string $labelKey = '';

    /** @var list<string> the permission strings under that heading, read before write */
    #[ApiProperty(writable: false, required: true, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public array $permissions = [];

    public static function of(PermissionGroup $group): self
    {
        $resource = new self();
        $resource->key = $group->key;
        $resource->labelKey = $group->labelKey;
        $resource->permissions = $group->permissions;

        return $resource;
    }
}
