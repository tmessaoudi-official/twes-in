<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\TaxKind;
use App\Fiscal\Domain\Unit;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductRepository;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<DeliveryNoteOptionsResource> */
final readonly class DeliveryNoteOptionsProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private EstablishmentRepository $establishments,
        private CustomerRepository $customers,
        private ProductRepository $products,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private CurrencyScales $scales,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DeliveryNoteOptionsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::READ);
        $companyId = $company->getId();

        $options = new DeliveryNoteOptionsResource();
        $options->currency = $company->getCurrency();
        $options->currencyScale = $this->scales->of($company->getCurrency());
        $options->establishments = array_map(static fn (Establishment $establishment): array => [
            'id' => $establishment->getId()->toRfc4122(),
            'code' => $establishment->getCode(),
            'name' => $establishment->getName(),
            'isDefault' => $establishment->isDefault(),
        ], $this->establishments->ofCompany($companyId));
        $options->customers = array_values(array_map(static fn (Customer $customer): array => [
            'id' => $customer->getId()->toRfc4122(),
            'number' => $customer->getNumber(),
            'name' => $customer->getProfile()->name,
            'excludedFamilies' => array_map(static fn (TaxFamily $family): string => $family->value, $customer->getTaxRegime()->getExcludedFamilies()),
        ], array_filter($this->customers->ofCompany($companyId), static fn (Customer $customer): bool => $customer->isActive())));
        $options->products = array_values(array_map(static fn (Product $product): array => [
            'id' => $product->getId()->toRfc4122(),
            'reference' => $product->getReference(),
            'name' => $product->getDetails()->name,
            'unitId' => $product->getUnit()->getId()->toRfc4122(),
            'unitPriceNet' => $product->getDetails()->unitPriceNet,
            'defaultTaxComponentIds' => $product->getDefaultTaxComponentIds(),
        ], array_filter($this->products->ofCompany($companyId), static fn (Product $product): bool => $product->isActive())));
        $options->units = array_values(array_map(static fn (Unit $unit): array => [
            'id' => $unit->getId()->toRfc4122(),
            'code' => $unit->getCode(),
            'name' => $unit->getName(),
            'decimals' => $unit->getDecimals(),
        ], array_filter($this->units->ofCompany($companyId), static fn (Unit $unit): bool => $unit->isActive())));
        $options->taxes = array_values(array_map(static fn (TaxComponent $tax): array => [
            'id' => $tax->getId()->toRfc4122(),
            'code' => $tax->getCode(),
            'name' => $tax->getName(),
            'family' => $tax->getFamily()->value,
            'rate' => Decimal::format(Decimal::of($tax->getRate() ?? '0'), 3),
            'entersVatBase' => $tax->entersVatBase(),
        ], array_filter($this->taxes->ofCompany($companyId), static fn (TaxComponent $tax): bool => $tax->isActive() && TaxKind::PercentageLine === $tax->getKind())));

        return $options;
    }
}
