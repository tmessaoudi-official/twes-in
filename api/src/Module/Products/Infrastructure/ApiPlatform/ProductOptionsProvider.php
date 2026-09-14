<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\TaxKind;
use App\Fiscal\Domain\Unit;
use App\Fiscal\Domain\UnitRepository;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<ProductOptionsResource> */
final readonly class ProductOptionsProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private CurrencyScales $scales,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductOptionsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        $options = new ProductOptionsResource();
        $options->currency = $company->getCurrency();
        $options->currencyScale = $this->scales->of($company->getCurrency());
        $units = array_filter($this->units->ofCompany($company->getId()), static fn (Unit $unit) => $unit->isActive());
        $options->units = array_values(array_map(static fn (Unit $unit) => new ProductUnitOption($unit->getId()->toRfc4122(), $unit->getCode(), $unit->getName(), $unit->getDecimals()), $units));
        $taxes = array_filter($this->taxes->ofCompany($company->getId()), static fn (TaxComponent $tax) => $tax->isActive() && TaxKind::PercentageLine === $tax->getKind());
        $options->taxes = array_values(array_map(static fn (TaxComponent $tax) => new ProductTaxOption($tax->getId()->toRfc4122(), $tax->getCode(), $tax->getName(), $tax->getFamily()->value), $taxes));

        return $options;
    }
}
