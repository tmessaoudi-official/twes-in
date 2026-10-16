<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\StepUp\RecentProof;
use App\Tenancy\Application\Company\RequireSecondFactor;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyProfileResource;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanySecurityResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * Turning the requirement off waits for a recent proof: a stolen session would otherwise take the second factor away
 * from every member in one request. Turning it on only adds protection, and saying what is already so changes nothing,
 * so neither asks.
 *
 * @implements ProcessorInterface<CompanySecurityResource, CompanySecurityResource>
 */
final readonly class RequireSecondFactorProcessor implements ProcessorInterface
{
    public function __construct(private RequireSecondFactor $require, private CompanyGuard $guard, private RecentProof $proof)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CompanySecurityResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);
        if ($company->isMfaRequired() && !$data->mfaRequired) {
            $this->proof->demand($this->guard->account()->getId());
        }
        $this->require->handle($company, $data->mfaRequired, $this->guard->account()->getId());

        return CompanySecurityResource::of($company, true);
    }
}
