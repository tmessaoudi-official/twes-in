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
use App\Fiscal\Domain\TaxKind;
use App\Fiscal\Domain\Unit;
use App\Fiscal\Domain\UnitRepository;
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
