<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;

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
