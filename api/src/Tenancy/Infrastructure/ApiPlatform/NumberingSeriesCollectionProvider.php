<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Numbering\ManageNumberingSeries;
use App\Tenancy\Domain\NumberingSeries;

/** @implements ProviderInterface<NumberingSeriesResource> */
final readonly class NumberingSeriesCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageNumberingSeries $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<NumberingSeriesResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::READ_PERMISSION);
        $today = $this->manage->today()->setTimezone(new \DateTimeZone($company->getTimezone()));

        return array_map(static fn (NumberingSeries $series) => NumberingSeriesResource::of($series, $today), $this->manage->list($company));
    }
}
