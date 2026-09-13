<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\TaxComponent\ManageTaxComponents;
use App\Fiscal\Domain\TaxComponent;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<TaxComponentResource> */
final readonly class TaxComponentCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageTaxComponents $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<TaxComponentResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), FiscalPermission::READ);

        return array_map(static fn (TaxComponent $component) => TaxComponentResource::of($component), $this->manage->list($company));
    }
}
