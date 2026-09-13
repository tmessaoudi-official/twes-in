<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fiscal\Application\Unit\ManageUnits;
use App\Fiscal\Application\Unit\UnitCodeTaken;
use App\Fiscal\Application\Unit\UnitDraft;
use App\Fiscal\Domain\InvalidFiscalValue;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<UnitResource, UnitResource> */
final readonly class CreateUnitProcessor implements ProcessorInterface
{
    public function __construct(private ManageUnits $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UnitResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), FiscalPermission::WRITE);

        try {
            $unit = $this->manage->create($company, new UnitDraft($data->code, $data->name, $data->decimals, $data->sortOrder), $this->guard->account()->getId());
        } catch (UnitCodeTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidFiscalValue $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return UnitResource::of($unit);
    }
}
