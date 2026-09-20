<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Permission\KnownPermissions;
use App\Tenancy\Application\Permission\PermissionGroup;

/** @implements ProviderInterface<PermissionGroupResource> */
final readonly class PermissionGroupCollectionProvider implements ProviderInterface
{
    public function __construct(private KnownPermissions $permissions, private CompanyGuard $guard)
    {
    }

    /** @return list<PermissionGroupResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);

        return array_map(static fn (PermissionGroup $group) => PermissionGroupResource::of($group), $this->permissions->groups());
    }
}
