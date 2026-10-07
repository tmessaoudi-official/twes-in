<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\ReadOnHand;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Application\Establishment\EstablishmentNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<StockOnHandResource> */
final readonly class StockOnHandProvider implements ProviderInterface
{
    public function __construct(private ReadOnHand $onHand, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StockOnHandResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);

        try {
            $establishment = $this->onHand->establishment($company, Paging::identifier($operation, 'establishmentId'));
        } catch (EstablishmentNotFound $absent) {
            throw new NotFoundHttpException('No such establishment.', $absent);
        }
        $resource = new StockOnHandResource();
        $resource->items = array_map(static fn (array $row): array => [
            'productId' => $row['product']->getId()->toRfc4122(),
            'unitId' => $row['product']->getUnit()->getId()->toRfc4122(),
            'onHand' => $row['onHand'],
        ], $this->onHand->at($company, $establishment, \array_slice(Paging::uuids($operation, 'ids'), 0, StockOnHandResource::MOST)));

        return $resource;
    }
}
