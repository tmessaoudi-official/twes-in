<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Quotes\Infrastructure\ApiPlatform\Resource\QuoteOptionsResource;
use App\Module\Quotes\Infrastructure\ApiPlatform\Security\QuotePermission;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<QuoteOptionsResource> */
final readonly class QuoteOptionsProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private EstablishmentRepository $establishments,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private CurrencyScales $scales,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): QuoteOptionsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::READ);
        $companyId = $company->getId();
        $decimal = static fn (?string $value): ?string => null === $value ? null : Decimal::format(Decimal::of($value), 3);

        $options = new QuoteOptionsResource();
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
            'kind' => $tax->getKind()->value,
            'family' => $tax->getFamily()->value,
            'rate' => $decimal($tax->getRate()),
            'amount' => $decimal($tax->getAmount()),
            'threshold' => $decimal($tax->getThreshold()),
            'entersVatBase' => $tax->entersVatBase(),
            'isDefault' => $tax->isDefault(),
        ], array_filter($this->taxes->ofCompany($companyId), static fn (TaxComponent $tax): bool => $tax->isActive())));

        return $options;
    }
}
