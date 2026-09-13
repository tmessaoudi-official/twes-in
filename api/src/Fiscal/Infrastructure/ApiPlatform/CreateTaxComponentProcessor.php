<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fiscal\Application\TaxComponent\ManageTaxComponents;
use App\Fiscal\Application\TaxComponent\TaxComponentCodeTaken;
use App\Fiscal\Application\TaxComponent\TaxComponentDraft;
use App\Fiscal\Domain\InvalidFiscalValue;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<TaxComponentResource, TaxComponentResource> */
final readonly class CreateTaxComponentProcessor implements ProcessorInterface
{
    public function __construct(private ManageTaxComponents $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TaxComponentResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), FiscalPermission::WRITE);

        try {
            $component = $this->manage->create(
                $company,
                new TaxComponentDraft($data->code, $data->name, $data->family, $data->rate, $data->amount, $data->threshold, $data->entersVatBase, $data->isDefault, $data->exemptionMention, $data->sortOrder),
                $this->guard->account()->getId(),
            );
        } catch (TaxComponentCodeTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidFiscalValue $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return TaxComponentResource::of($component);
    }
}
