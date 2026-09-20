<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<StockOptionsResource> */
final readonly class StockOptionsProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private EstablishmentRepository $establishments,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StockOptionsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);

        $options = new StockOptionsResource();
        $options->establishments = array_map(static fn (Establishment $establishment) => new StockEstablishmentOption(
            $establishment->getId()->toRfc4122(),
            $establishment->getCode(),
            $establishment->getName(),
        ), $this->establishments->ofCompany($company->getId()));

        return $options;
    }
}
