<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Role\ManageRoles;
use App\Tenancy\Application\Role\RoleView;

/** @implements ProviderInterface<RoleResource> */
final readonly class RoleCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageRoles $roles, private CompanyGuard $guard)
    {
    }

    /** @return list<RoleResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);

        return array_map(static fn (RoleView $view) => RoleResource::of($view), $this->roles->list($company));
    }
}
