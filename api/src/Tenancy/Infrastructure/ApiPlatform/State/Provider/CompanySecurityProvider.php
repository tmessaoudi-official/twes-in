<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyProfileResource;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanySecurityResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<CompanySecurityResource> */
final readonly class CompanySecurityProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CompanySecurityResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::READ_PERMISSION);

        return CompanySecurityResource::of($company, $this->guard->may($company, CompanyProfileResource::WRITE_PERMISSION));
    }
}
