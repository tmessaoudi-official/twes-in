<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Export;

use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLevelSearch;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockLevelSearchReader;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The stock levels list as a file (docs/SPEC.md § 7, row 60): one row per product, location and lot, under the words,
 * location and establishment the screen shows. Quantities go out as the decimals the stock holds. Nothing here says
 * what the goods cost: the valuation, behind its own permission, is where a cost is read.
 */
final readonly class StockLevelExport implements DeclaresExport
{
    private const int BATCH = 200;

    public function __construct(
        private KeepStock $stock,
        private ProductRepository $products,
        private StockLocationRepository $locations,
    ) {
    }

    public function key(): string
    {
        return 'stock-levels';
    }

    public function permission(): string
    {
        return StockPermission::READ;
    }

    public function module(): string
    {
        return InventoryModule::KEY;
    }

    public function columns(Company $company): array
    {
        return ['product_reference', 'product', 'unit_code', 'location_code', 'location', 'lot_code', 'lot_expires_on', 'quantity'];
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $search = StockLevelSearchReader::read($query->parameters(), $query->text(), $query->order(StockLevelSearch::SORTS), $company);

        for ($page = 1;; ++$page) {
            $answer = $this->stock->searchLevels($company, $search, new PageRequest($page, self::BATCH));
            $products = [];
            foreach ($this->products->ofIdsInCompany(array_map(static fn (StockLevel $level): Uuid => $level->productId, $answer->items), $company->getId()) as $product) {
                $products[$product->getId()->toRfc4122()] = $product;
            }
            $locations = [];
            foreach ($this->locations->ofIdsInCompany(array_map(static fn (StockLevel $level): Uuid => $level->locationId, $answer->items), $company->getId()) as $place) {
                $locations[$place->getId()->toRfc4122()] = $place;
            }
            foreach ($answer->items as $level) {
                yield self::row($level, $products[$level->productId->toRfc4122()] ?? null, $locations[$level->locationId->toRfc4122()] ?? null);
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }

    /**
     * A row names what it holds even when the product or the location has since gone, as the list does: leaving it out
     * would make a stock vanish from the file rather than say whose it is.
     *
     * @return list<string>
     */
    private static function row(StockLevel $level, ?Product $product, ?StockLocation $location): array
    {
        return [
            $product?->getReference() ?? '',
            $product?->getDetails()->name ?? '',
            $product?->getUnit()->getCode() ?? '',
            $location?->getCode() ?? '',
            $location?->getName() ?? '',
            $level->lotCode ?? '',
            $level->lotExpiresOn ?? '',
            $level->quantity,
        ];
    }
}
