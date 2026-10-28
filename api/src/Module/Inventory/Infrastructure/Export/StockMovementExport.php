<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Export;

use App\ImportExport\Application\Export\DeclaresExport;
use App\ImportExport\Application\Export\ExportQuery;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\ValueObject\StockMovementSearch;
use App\Module\Inventory\Infrastructure\ApiPlatform\Payload\StockMovementSearchReader;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;

/**
 * The stock movements list as a file (docs/SPEC.md § 7, row 60): one row per movement, under the product, location,
 * words, kind, source, lot and order the screen shows, and what the screen marks on a row: a receipt whose cost waits
 * to be entered, a sale that left its place below zero with no count since (« Sortie sans stock »). The list never
 * shows what a receipt cost, so the file has no cost column for anyone, whatever they may read elsewhere.
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
        return ['date', 'product_reference', 'product', 'location_code', 'location', 'kind', 'quantity', 'unit_code', 'source', 'lot_code', 'lot_expires_on', 'reason', 'note', 'cost_to_complete', 'without_stock'];
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $search = StockMovementSearchReader::read($query->parameters(), $query->text(), $query->order(StockMovementSearch::SORTS), $company);

        for ($page = 1;; ++$page) {
            $answer = $this->stock->searchMovements($company, $search, new PageRequest($page, self::BATCH));
            // What the list marks on a row is a column of its file, read once for the page as the list reads it.
            $short = array_flip($this->stock->soldWithoutStock($company, $answer->items));
            foreach ($answer->items as $movement) {
                yield self::row($movement, isset($short[$movement->getId()->toRfc4122()]));
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }

    /** @return list<string> */
    private static function row(StockMovement $movement, bool $withoutStock): array
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
            $movement->isCostToComplete() ? 'yes' : 'no',
            $withoutStock ? 'yes' : 'no',
        ];
    }
}
