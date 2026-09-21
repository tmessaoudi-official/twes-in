<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Inventory\Domain\ProductHomeLocation;
use App\Module\Inventory\Domain\ProductHomeLocationRepository;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Where each product normally lives (docs/SPEC.md row 101). A home is a proposal, not a rule: it is what a receipt
 * offers so a person putting goods away answers the same question once rather than every time, and nothing here
 * refuses a movement to anywhere else.
 *
 * One home per product per establishment, so setting one in an establishment that already has one MOVES it rather
 * than adding a second — the establishment is the location's own, never asked for separately, because a location
 * already knows where it is and two sources for one fact drift.
 */
final readonly class KeepProductHomes
{
    public const string ENTITY_TYPE = 'product_home_location';
    public const string SET = 'product_home_location.set';
    public const string CLEARED = 'product_home_location.cleared';

    public function __construct(
        private ProductHomeLocationRepository $homes,
        private StockLocationRepository $locations,
        private ProductRepository $products,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /**
     * Every home this product has, by establishment code.
     *
     * @return list<ProductHomeLocation>
     *
     * @throws ProductNotInCompany
     */
    public function of(Company $company, Uuid $productId): array
    {
        $this->product($company, $productId);

        return $this->homes->ofProduct($productId, $company->getId());
    }

    /**
     * What a picker proposes for each of these products: the location of its home, keyed by the product's
     * identifier, and ONLY where the product has exactly one.
     *
     * A product with a home in two establishments is left without a proposal on purpose. A picker knows which
     * product was chosen, not which establishment the goods are arriving at, so naming one of the two would be
     * right half the time and silently wrong the other half — and a wrong shelf proposed is worse than none,
     * because it is accepted without being read.
     *
     * @param list<Uuid> $productIds
     *
     * @return array<string, Uuid> the location, by product identifier
     */
    public function proposed(Company $company, array $productIds): array
    {
        $proposals = [];
        foreach ($this->homes->ofProducts($productIds, $company->getId()) as $productId => $homes) {
            if (1 === \count($homes)) {
                $proposals[$productId] = $homes[0]->getLocation()->getId();
            }
        }

        return $proposals;
    }

    /**
     * Gives the product a home at this location, moving the one its establishment already had.
     *
     * @throws ProductNotInCompany
     * @throws InvalidStockLocation
     */
    public function set(Company $company, Uuid $productId, Uuid $locationId, ?Uuid $actorUserId): ProductHomeLocation
    {
        return $this->transactions->run(function () use ($company, $productId, $locationId, $actorUserId): ProductHomeLocation {
            $product = $this->product($company, $productId);
            $location = $this->locations->ofIdInCompany($locationId, $company->getId())
                ?? throw new InvalidStockLocation('locationId', 'No stock location of this company has this id.');

            $existing = $this->homes->ofProductInEstablishment($productId, $location->getEstablishment()->getId());
            if (null === $existing) {
                $home = ProductHomeLocation::at($product, $location, $this->clock->now());
                $this->homes->save($home);
                $this->record($company, $home, self::SET, $actorUserId);

                return $home;
            }
            // Dropped where it already was: no row, so no other screen is told a home moved that did not.
            if ($existing->moveTo($location, $this->clock->now())) {
                $this->homes->save($existing);
                $this->record($company, $existing, self::SET, $actorUserId);
            }

            return $existing;
        });
    }

    /**
     * Takes away the home this product has in that establishment. A product with none is not an error: clearing what
     * is already clear is what a person pressing the same button twice means.
     *
     * @throws ProductNotInCompany
     */
    public function clear(Company $company, Uuid $productId, Uuid $establishmentId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $productId, $establishmentId, $actorUserId): void {
            $this->product($company, $productId);
            $home = $this->homes->ofProductInEstablishment($productId, $establishmentId);
            if (null === $home) {
                return;
            }
            $this->homes->remove($home);
            $this->record($company, $home, self::CLEARED, $actorUserId);
        });
    }

    /** @throws ProductNotInCompany */
    private function product(Company $company, Uuid $productId): Product
    {
        $product = $this->products->ofIdInCompany($productId, $company->getId());
        if (null === $product) {
            throw new ProductNotInCompany();
        }

        return $product;
    }

    private function record(Company $company, ProductHomeLocation $home, string $action, ?Uuid $actorUserId): void
    {
        // Keyed on the PRODUCT, not on the home: what a screen reloads on is the product whose home changed, and a
        // cleared home's own identifier names a row that no longer exists.
        $this->audit->record(new AuditEntry(
            self::ENTITY_TYPE,
            $home->getProduct()->getId(),
            $action,
            $actorUserId,
            ['establishmentId' => $home->getEstablishment()->getId()->toRfc4122()],
            companyId: $company->getId(),
        ));
    }
}
