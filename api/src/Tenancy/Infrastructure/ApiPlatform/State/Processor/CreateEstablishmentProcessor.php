<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Application\Establishment\EstablishmentCodeTaken;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Domain\Exception\InvalidEstablishment;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyProfileResource;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\EstablishmentResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @implements ProcessorInterface<EstablishmentResource, EstablishmentResource> */
final readonly class CreateEstablishmentProcessor implements ProcessorInterface
{
    public function __construct(private ManageEstablishments $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EstablishmentResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);

        try {
            $establishment = $this->manage->create($company, $data->details(), $this->guard->account()->getId());
        } catch (EstablishmentCodeTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidEstablishment|InvalidNumbering $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return EstablishmentResource::of($establishment, $this->manage->codePattern($company), false, $this->manage->contactsToCheck($company, $establishment));
    }
}
