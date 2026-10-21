<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Fiscal\Domain\ValueObject\TaxKind;
use App\Module\Products\Application\Photo\ProductPhotos;
use App\Module\Products\Application\ProductCheckScopes;
use App\Module\Products\Domain\ValueObject\ProductCheckReason;
use App\Module\Products\Infrastructure\ApiPlatform\Output\ProductTaxOption;
use App\Module\Products\Infrastructure\ApiPlatform\Output\ProductUnitOption;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductOptionsResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<ProductOptionsResource> */
final readonly class ProductOptionsProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private CurrencyScales $scales,
        private ProductPhotos $photos,
        private ProductCheckScopes $checkScopes,
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
        $options->taxes = array_values(array_map(static fn (TaxComponent $tax) => new ProductTaxOption($tax->getId()->toRfc4122(), $tax->getCode(), $tax->getName(), $tax->getFamily()->value, $tax->isDefault()), $taxes));
        $options->photosPerProduct = $this->photos->perProduct();
        $options->photoMaxBytes = $this->photos->maxBytes();
        $scope = $this->checkScopes->of($company, $this->guard->may($company, ProductPermission::COST_READ));
        $options->checkReasons = array_map(static fn (ProductCheckReason $reason): string => $reason->value, $scope->reasons());

        return $options;
    }
}
