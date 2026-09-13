<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;

/** @implements ProviderInterface<CompanyProfileResource> */
final readonly class CompanyProfileProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private CompanyProfileRepresentation $representation)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CompanyProfileResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::READ_PERMISSION);

        return $this->representation->of($company);
    }
}
