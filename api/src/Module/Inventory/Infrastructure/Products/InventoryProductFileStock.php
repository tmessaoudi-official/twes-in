<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Products;

use App\Module\Inventory\Application\ImportStock;
use App\Module\Inventory\Application\StockChange;
use App\Module\Inventory\Application\StockImportRefused;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Products\Application\ProductFileStock;
use App\Module\Products\Application\ProductStockChange;
use App\Module\Products\Application\ProductStockRefused;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductRepository;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Company;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the catalogue's `ProductFileStock` port out of this module, through the same ImportStock the opening-stock
 * file uses, so both files add, count and choose a place by one rule.
 *
 * The movement a row wrote leaves the unit of work here: the product import detaches its product after each row, and
 * a movement left managed would point at it, so the next row's flush would take that product for a new one.
 */
final readonly class InventoryProductFileStock implements ProductFileStock
{
    public function __construct(
        private ImportStock $stock,
        private ProductRepository $products,
        private ModuleStates $modules,
        private CompanyGuard $guard,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function offered(Company $company): bool
    {
        return $this->modules->isEnabled($company->getId(), InventoryModule::KEY) && $this->guard->may($company, StockPermission::WRITE);
    }

    public function add(Company $company, Uuid $productId, ?string $locationCode, ?Uuid $homeLocationId, string $quantity, ?string $unitCost, ?Uuid $actorUserId, Uuid $runId): ProductStockChange
    {
        return $this->changing($company, $productId, $locationCode, $homeLocationId, fn (Product $product, StockLocation $place): StockChange => $this->stock->add($company, $product, $place, $quantity, $unitCost, $actorUserId, $runId));
    }

    public function count(Company $company, Uuid $productId, ?string $locationCode, ?Uuid $homeLocationId, string $counted, bool $recount, ?Uuid $actorUserId, Uuid $runId): ProductStockChange
    {
        return $this->changing($company, $productId, $locationCode, $homeLocationId, fn (Product $product, StockLocation $place): StockChange => $this->stock->count($company, $product, $place, $counted, $recount, $actorUserId, $runId));
    }

    public function finished(Company $company, Uuid $runId, ?Uuid $actorUserId): void
    {
        $this->stock->finished($company, $runId, $actorUserId);
    }

    /**
     * @param callable(Product, StockLocation): StockChange $change
     *
     * @throws ProductStockRefused
     */
    private function changing(Company $company, Uuid $productId, ?string $locationCode, ?Uuid $homeLocationId, callable $change): ProductStockChange
    {
        $product = $this->products->ofIdInCompany($productId, $company->getId())
            ?? throw new ProductStockRefused(ProductStockRefused::PRODUCT, 'not_stocked', 'No product of this company has this id.');
        try {
            $this->stock->stocked($product);
            $done = $change($product, $this->stock->placeOf($company, $product, $locationCode, $homeLocationId));
            $this->entityManager->detach($done->movement);

            return new ProductStockChange($done->place, $done->before, $done->after);
        } catch (StockImportRefused $refused) {
            throw new ProductStockRefused($refused->about, $refused->reason, $refused->getMessage(), $refused->params, $refused);
        }
    }
}
