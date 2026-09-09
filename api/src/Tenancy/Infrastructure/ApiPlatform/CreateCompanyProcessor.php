<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Company\CompanyNameTaken;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Application\Company\NewCompany;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @implements ProcessorInterface<CompanyResource, CompanyResource> */
final readonly class CreateCompanyProcessor implements ProcessorInterface
{
    public function __construct(private CreateCompany $createCompany, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CompanyResource
    {
        try {
            $company = $this->createCompany->handle(
                new NewCompany($data->name, $data->countryCode, $data->currency, $data->locale, $data->timezone),
                $this->guard->account()->getId(),
            );
        } catch (CompanyNameTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        }

        return CompanyResource::of($company);
    }
}
