<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Company\CompanySummary;
use App\Tenancy\Application\Company\ListCompaniesOfUser;

/** @implements ProviderInterface<WorkingCompanyResource> */
final readonly class WorkingCompanyProvider implements ProviderInterface
{
    public function __construct(private ListCompaniesOfUser $listCompanies, private CompanyGuard $guard)
    {
    }

    /** @return list<WorkingCompanyResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(
            static fn (CompanySummary $summary) => WorkingCompanyResource::of($summary),
            $this->listCompanies->for($this->guard->account()->getId()),
        );
    }
}
