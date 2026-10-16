<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\Unit\ManageUnits;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Infrastructure\ApiPlatform\Resource\UnitResource;
use App\Fiscal\Infrastructure\ApiPlatform\Security\FiscalPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<UnitResource> */
final readonly class UnitCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageUnits $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<UnitResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), FiscalPermission::READ);

        return array_map(static fn (Unit $unit) => UnitResource::of($unit), $this->manage->list($company));
    }
}
