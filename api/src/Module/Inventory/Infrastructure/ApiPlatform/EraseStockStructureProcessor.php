<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Venue\Application\ArrangeVenue;
use App\Venue\Application\VenueStructureNotFound;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<StockStructureResource, null> */
final readonly class EraseStockStructureProcessor implements ProcessorInterface
{
    public function __construct(private ArrangeVenue $venue, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);

        try {
            $this->venue->removeStructure($company, CompanyPath::identifier($uriVariables, 'structureId'), $this->guard->account()->getId());
        } catch (VenueStructureNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return null;
    }
}
