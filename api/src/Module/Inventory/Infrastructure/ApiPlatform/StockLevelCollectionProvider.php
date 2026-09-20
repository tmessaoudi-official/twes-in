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
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLevelSearch;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

/**
 * One page of the stock a company holds, grouped, searched, narrowed and sorted in the database (docs/SPEC.md § 7,
 * lists at scale). The products and locations a row names are read for that page alone, so the list no longer loads
 * every product and every location of the company to show twenty-five rows.
 *
 * @implements ProviderInterface<StockLevelResource>
 */
final readonly class StockLevelCollectionProvider implements ProviderInterface
{
    public function __construct(
        private KeepStock $stock,
        private ProductRepository $products,
        private StockLocationRepository $locations,
        private CompanyGuard $guard,
        private Paging $paging,
    ) {
    }

    /** @return TraversablePaginator<StockLevelResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);
        $search = new StockLevelSearch(
            Paging::text($operation),
            // The `uuid` format has already refused anything that is not an identifier, with a 422.
            self::identifier($operation, 'locationId'),
            self::identifier($operation, 'establishmentId'),
            Paging::order($operation, StockLevelSearch::SORTS),
        );
        $page = $this->stock->searchLevels($company, $search, $this->paging->request($operation, $context));

        $products = [];
        foreach ($this->products->ofIdsInCompany(array_map(static fn (StockLevel $level): Uuid => $level->productId, $page->items), $company->getId()) as $product) {
            $products[$product->getId()->toRfc4122()] = $product;
        }
        $locations = [];
        foreach ($this->locations->ofIdsInCompany(array_map(static fn (StockLevel $level): Uuid => $level->locationId, $page->items), $company->getId()) as $location) {
            $locations[$location->getId()->toRfc4122()] = $location;
        }

        return $this->paging->paginator(
            $page,
            static fn (StockLevel $level): StockLevelResource => self::row(
                $level,
                $products[$level->productId->toRfc4122()] ?? null,
                $locations[$level->locationId->toRfc4122()] ?? null,
            ),
        );
    }

    /**
     * A row names what it holds even when the product or the location has since gone: the grouping found movements of
     * them, so leaving the row out would make a stock disappear rather than say whose it is.
     */
    private static function row(StockLevel $level, ?Product $product, ?StockLocation $location): StockLevelResource
    {
        $row = new StockLevelResource();
        $row->productId = $level->productId->toRfc4122();
        $row->productReference = $product?->getReference() ?? '';
        $row->productName = $product?->getDetails()->name ?? '';
        $row->unitCode = $product?->getUnit()->getCode() ?? '';
        $row->unitDecimals = $product?->getUnit()->getDecimals() ?? $row->unitDecimals;
        $row->locationId = $level->locationId->toRfc4122();
        $row->locationCode = $location?->getCode() ?? '';
        $row->locationName = $location?->getName() ?? '';
        $row->establishmentId = $location?->getEstablishment()->getId()->toRfc4122() ?? '';
        $row->quantity = $level->quantity;
        $row->id = $row->productId.':'.$row->locationId;

        return $row;
    }

    private static function identifier(Operation $operation, string $key): ?Uuid
    {
        $value = Paging::value($operation, $key);

        return \is_string($value) ? Uuid::fromString($value) : null;
    }
}
