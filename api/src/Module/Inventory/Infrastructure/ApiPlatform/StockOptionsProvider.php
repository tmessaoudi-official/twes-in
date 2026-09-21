<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
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
        private ReadSetting $settings,
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

        // The palette's sizes, resolved for this company. The order is the palette's own, which is why it is
        // written here rather than read off the catalogue: `Rayonnage` first, because it is the shape posed most.
        $context = new SettingContext($company);
        $options->planShapes = array_map(fn (string $shape) => new StockPlanShapeOption(
            $shape,
            $this->metres($context, "venue.shape.$shape.width"),
            $this->metres($context, "venue.shape.$shape.depth"),
        ), ['rack', 'zone', 'aisle', 'dock']);

        return $options;
    }

    /**
     * One side of a palette shape. A `SettingType::Decimal` is carried as a decimal STRING, so anything else means
     * the declaration and this reader disagree — a programming error, not something a company could have typed.
     */
    private function metres(SettingContext $context, string $key): string
    {
        $metres = $this->settings->value($context, $key);

        return \is_string($metres) ? $metres : throw new \LogicException("$key is not a measurement");
    }
}
