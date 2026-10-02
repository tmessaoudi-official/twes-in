<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Products\Application\ManagePriceLists;
use App\Module\Products\Application\PriceListNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<PriceListResource, null> */
final readonly class DeletePriceListProcessor implements ProcessorInterface
{
    public function __construct(private ManagePriceLists $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $this->manage->delete($company, CompanyPath::identifier($uriVariables, 'priceListId'), $this->guard->account()->getId());
        } catch (PriceListNotFound $absent) {
            throw new NotFoundHttpException('No such price list.', $absent);
        }

        return null;
    }
}
