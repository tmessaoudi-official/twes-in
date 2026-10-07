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
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockMovementSearchReader;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;

/**
 * The stock movements list as a file (docs/SPEC.md § 7, row 60): one row per movement, under the product, location,
 * words, kind, source, lot and order the screen shows. The list never shows what a receipt cost, so the file has no
 * cost column for anyone, whatever they may read elsewhere.
 */
final readonly class StockMovementExport implements DeclaresExport
{
    private const int BATCH = 200;

    public function __construct(private KeepStock $stock)
    {
    }

    public function key(): string
    {
        return 'stock-movements';
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
        return ['date', 'product_reference', 'product', 'location_code', 'location', 'kind', 'quantity', 'unit_code', 'source', 'lot_code', 'lot_expires_on', 'reason', 'note'];
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $search = StockMovementSearchReader::read($query->parameters(), $query->text(), $query->order(StockMovementSearch::SORTS), $company);

        for ($page = 1;; ++$page) {
            $answer = $this->stock->searchMovements($company, $search, new PageRequest($page, self::BATCH));
            foreach ($answer->items as $movement) {
                yield self::row($movement);
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }

    /** @return list<string> */
    private static function row(StockMovement $movement): array
    {
        $product = $movement->getProduct();
        $location = $movement->getLocation();

        return [
            $movement->getAt()->format(\DATE_ATOM),
            $product->getReference(),
            $product->getDetails()->name,
            $location->getCode(),
            $location->getName(),
            $movement->getKind()->value,
            $movement->getQuantity(),
            $product->getUnit()->getCode(),
            $movement->getSourceType(),
            $movement->getLot()?->getCode() ?? '',
            $movement->getLot()?->getExpiresOn()?->format('Y-m-d') ?? '',
            $movement->getReason()->value ?? '',
            $movement->getNote() ?? '',
        ];
    }
}
