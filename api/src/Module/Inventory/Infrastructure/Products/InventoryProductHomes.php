<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Products;

use App\Module\Inventory\Application\KeepProductHomes;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Products\Application\ProductHomes;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the catalogue's `ProductHomes` port out of this module, the way `DbalProductStockHistory` answers its
 * neighbour: the dependency runs inventory → catalogue, so what a product file needs of the warehouse is declared
 * there and implemented here.
 */
final readonly class InventoryProductHomes implements ProductHomes
{
    public function __construct(
        private StockLocationRepository $locations,
        private KeepProductHomes $homes,
        private ModuleStates $modules,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function offered(Company $company): bool
    {
        return $this->modules->isEnabled($company->getId(), InventoryModule::KEY);
    }

    public function locationsCoded(Company $company, string $code): array
    {
        return array_map(
            static fn (StockLocation $location): Uuid => $location->getId(),
            $this->locations->ofCodeInCompany($code, $company->getId()),
        );
    }

    /**
     * The home is dropped from the unit of work once written, because the one caller of this port is an IMPORT and
     * a file is many rows. The importer detaches each product after writing it — every flush walks every managed
     * entity, or a file costs the square of its length — and a home left managed still points at that detached
     * product, so the NEXT row's flush finds an object Doctrine no longer knows and takes it for a new entity. The
     * second row of a two-row file then dies where the first passed.
     *
     * It is dropped HERE rather than in `KeepProductHomes`, whose other caller is the HTTP surface: one request
     * writes one home and reads it straight back, and detaching there would be a batching concern imposed on a
     * screen that has none. Nothing reads the home back through this port.
     */
    public function setHome(Company $company, Uuid $productId, Uuid $locationId, ?Uuid $actorUserId): void
    {
        $this->entityManager->detach($this->homes->set($company, $productId, $locationId, $actorUserId));
    }
}
