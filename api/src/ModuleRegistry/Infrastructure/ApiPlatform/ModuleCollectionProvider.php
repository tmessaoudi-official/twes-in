<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ModuleRegistry\Application\ManageModules;
use App\ModuleRegistry\Application\ModuleInterests;
use App\ModuleRegistry\Application\ModuleView;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<ModuleResource> */
final readonly class ModuleCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageModules $manage, private ModuleInterests $interests, private CompanyGuard $guard)
    {
    }

    /** @return list<ModuleResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ModulePermission::READ);

        $waiting = $this->interests->keysOf($company->getId());

        return array_map(static fn (ModuleView $view) => ModuleResource::of($view, \in_array($view->manifest->key, $waiting, true)), $this->manage->list($company));
    }
}
