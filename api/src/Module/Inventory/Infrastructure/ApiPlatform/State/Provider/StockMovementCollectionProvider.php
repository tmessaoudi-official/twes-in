<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\KeepLossAttachments;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\ValueObject\StockMovementSearch;
use App\Module\Inventory\Infrastructure\ApiPlatform\Payload\StockMovementSearchReader;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockMovementResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

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
    public function __construct(private KeepStock $stock, private KeepLossAttachments $attachments, private CompanyGuard $guard, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<StockMovementResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);
        $search = StockMovementSearchReader::read(Paging::parameters($context), Paging::text($operation), Paging::order($operation, StockMovementSearch::SORTS), $company);

        $page = $this->stock->searchMovements($company, $search, $this->paging->request($operation, $context));
        // The losses' files counted once for the page, not once per row.
        $counts = $this->attachments->attachmentCounts($company, $page->items);
        // Which sales of the page left their place short, read once for the page as well.
        $short = array_flip($this->stock->soldWithoutStock($company, $page->items));

        return $this->paging->paginator($page, static function (StockMovement $movement) use ($counts, $short): StockMovementResource {
            $resource = StockMovementResource::of($movement);
            $resource->attachmentCount = $counts[$movement->getId()->toRfc4122()] ?? $resource->attachmentCount;
            $resource->withoutStock = isset($short[$movement->getId()->toRfc4122()]);

            return $resource;
        });
    }
}
