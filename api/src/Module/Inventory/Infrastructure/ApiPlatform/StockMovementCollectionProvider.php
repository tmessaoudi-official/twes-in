<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementKind;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * One page of a company's movements, newest first (docs/SPEC.md § 7, lists at scale, row 55 (b)).
 *
 * This list used to answer every movement the company had, capped at two hundred, for the browser to cut into
 * pages — so a company past that cap silently stopped seeing its older history. It is the fastest-growing table
 * here, which is why it is paged in the database rather than trimmed.
 *
 * @implements ProviderInterface<StockMovementResource>
 */
final readonly class StockMovementCollectionProvider implements ProviderInterface
{
    public function __construct(private KeepStock $stock, private CompanyGuard $guard, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<StockMovementResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);
        $search = new StockMovementSearch(
            Paging::identifier($operation, 'productId'),
            Paging::identifier($operation, 'locationId'),
            Paging::text($operation),
            StockMovementKind::tryFrom((string) Paging::text($operation, 'kind')),
            Paging::text($operation, 'sourceType'),
            Paging::order($operation, StockMovementSearch::SORTS),
            Paging::text($operation, 'lot'),
        );

        return $this->paging->paginator(
            $this->stock->searchMovements($company, $search, $this->paging->request($operation, $context)),
            static fn (StockMovement $movement): StockMovementResource => StockMovementResource::of($movement),
        );
    }
}
