<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Company\RequireSecondFactor;

/** @implements ProcessorInterface<CompanySecurityResource, CompanySecurityResource> */
final readonly class RequireSecondFactorProcessor implements ProcessorInterface
{
    public function __construct(private RequireSecondFactor $require, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CompanySecurityResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);
        $this->require->handle($company, $data->mfaRequired, $this->guard->account()->getId());

        return CompanySecurityResource::of($company, true);
    }
}
