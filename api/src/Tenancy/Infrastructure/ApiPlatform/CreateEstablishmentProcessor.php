<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Establishment\EstablishmentCodeTaken;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Domain\InvalidEstablishment;
use App\Tenancy\Domain\InvalidNumbering;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

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
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return EstablishmentResource::of($establishment, $this->manage->codePattern($company));
    }
}
