<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\FirstSteps\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\FirstSteps\Application\FirstStepsCatalogue;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<FirstStepsResource> */
final readonly class FirstStepsProvider implements ProviderInterface
{
    /** Anyone who may read the company may read its first steps; each step then asks the permission that does it. */
    private const string PERMISSION = 'company.read';

    public function __construct(private CompanyGuard $guard, private FirstStepsCatalogue $catalogue)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FirstStepsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), self::PERMISSION);

        return FirstStepsResource::of($this->catalogue->stepsFor($company, fn (string $permission): bool => $this->guard->may($company, $permission)));
    }
}
