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
use App\Shared\Domain\Tree;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Where each product normally lives (docs/SPEC.md row 101, § 7 2026-10-04 09:04). A home is a proposal, not a rule: it
 * is what a receipt offers so a person putting goods away answers the same question once rather than every time, and
 * nothing here refuses a movement to anywhere else.
 *
 * A product may be kept at several places of one establishment, in order, the first being its main home, which is the
 * one a receipt proposes. The list of an establishment is written as a whole: the places named are its homes, in that
 * order, and any it had that are not named stop being homes. The order is rewritten for all of them at once, which is
 * why it is not unique in the database: a swap of the first and the second would collide on its first update.
 *
 * The establishment is the location's own, never asked for separately, because a location already knows where it is
 * and two sources for one fact drift.
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
     * Every home this product has, by establishment code and in order.
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
     * The goods at home at this place or at any place under it — a rack answers for its bins — so a place that should
     * hold something and holds nothing can say so.
     *
     * @return list<ProductHomeLocation>
     *
     * @throws InvalidStockLocation
     */
    public function at(Company $company, Uuid $locationId): array
    {
        $parents = [];
        foreach ($this->locations->ofCompany($company->getId()) as $location) {
            $parents[$location->getId()->toRfc4122()] = $location->getParent()?->getId()->toRfc4122();
        }
        if (!\array_key_exists($locationId->toRfc4122(), $parents)) {
            throw new InvalidStockLocation('locationId', 'No stock location of this company has this id.');
        }

        return $this->homes->atLocations(Tree::withDescendants([$locationId], $parents), $company->getId());
    }

    /**
     * What a picker proposes for each of these products: the location of its MAIN home, keyed by the product's
     * identifier, and ONLY where its homes are all in one establishment.
     *
     * A product with homes in two establishments is left without a proposal on purpose. A picker knows which product
     * was chosen, not which establishment the goods are arriving at, so naming one of the two would be right half the
     * time and silently wrong the other half — and a wrong shelf proposed is worse than none, because it is accepted
     * without being read. Several homes in ONE establishment are not that doubt: the first is the main one.
     *
     * @param list<Uuid> $productIds
     *
     * @return array<string, Uuid> the location, by product identifier
     */
    public function proposed(Company $company, array $productIds): array
    {
        $proposals = [];
        foreach ($this->homes->ofProducts($productIds, $company->getId()) as $productId => $homes) {
            $establishments = array_unique(array_map(static fn (ProductHomeLocation $home): string => $home->getEstablishment()->getId()->toRfc4122(), $homes));
            if (1 === \count($establishments)) {
                $proposals[$productId] = $homes[0]->getLocation()->getId();
            }
        }

        return $proposals;
    }

    /**
     * Makes this place the MAIN home of its establishment, the homes it already had following it in their order.
     *
     * @throws ProductNotInCompany
     * @throws InvalidStockLocation
     */
    public function set(Company $company, Uuid $productId, Uuid $locationId, ?Uuid $actorUserId): ProductHomeLocation
    {
        $location = $this->locations->ofIdInCompany($locationId, $company->getId())
            ?? throw new InvalidStockLocation('locationId', 'No stock location of this company has this id.');
        $establishmentId = $location->getEstablishment()->getId();
        $others = array_values(array_filter(
            array_map(static fn (ProductHomeLocation $home): Uuid => $home->getLocation()->getId(), $this->homes->ofProductInEstablishment($productId, $establishmentId)),
            static fn (Uuid $id): bool => !$id->equals($locationId),
        ));

        foreach ($this->replace($company, $productId, $establishmentId, [$locationId, ...$others], $actorUserId) as $home) {
            if ($home->getLocation()->getId()->equals($locationId)) {
                return $home;
            }
        }
        throw new \LogicException('The place just made a home is not among the homes.');
    }

    /**
     * Writes the homes of one establishment as this list, in this order: the first is the main one.
     *
     * @param list<Uuid> $locationIds
     *
     * @return list<ProductHomeLocation> the homes now, in order
     *
     * @throws ProductNotInCompany
     * @throws InvalidStockLocation
     */
    public function replace(Company $company, Uuid $productId, Uuid $establishmentId, array $locationIds, ?Uuid $actorUserId): array
    {
        return $this->transactions->run(function () use ($company, $productId, $establishmentId, $locationIds, $actorUserId): array {
            $product = $this->product($company, $productId);
            $places = [];
            foreach ($locationIds as $locationId) {
                $key = $locationId->toRfc4122();
                if (isset($places[$key])) {
                    throw new InvalidStockLocation('locationIds', 'A place is named once.');
                }
                $location = $this->locations->ofIdInCompany($locationId, $company->getId())
                    ?? throw new InvalidStockLocation('locationIds', 'No stock location of this company has this id.');
                if (!$location->getEstablishment()->getId()->equals($establishmentId)) {
                    throw new InvalidStockLocation('locationIds', 'A home is a place of the establishment it is listed under.');
                }
                $places[$key] = $location;
            }

            $now = $this->clock->now();
            $changed = false;
            $existing = [];
            foreach ($this->homes->ofProductInEstablishment($productId, $establishmentId) as $home) {
                $existing[$home->getLocation()->getId()->toRfc4122()] = $home;
            }
            $homes = [];
            $position = 0;
            foreach ($places as $key => $location) {
                $home = $existing[$key] ?? null;
                if (null === $home) {
                    $home = ProductHomeLocation::at($product, $location, $position, $now);
                    $changed = true;
                } elseif ($home->placeAt($position, $now)) {
                    $changed = true;
                } else {
                    ++$position;
                    $homes[] = $home;
                    continue;
                }
                $this->homes->save($home);
                $homes[] = $home;
                ++$position;
            }
            foreach ($existing as $key => $home) {
                if (!isset($places[$key])) {
                    $this->homes->remove($home);
                    $changed = true;
                }
            }
            if ($changed) {
                $this->record($company, $product, $establishmentId, [] === $homes ? self::CLEARED : self::SET, $actorUserId);
            }

            return $homes;
        });
    }

    /**
     * Takes away every home this product has in that establishment. A product with none is not an error: clearing what
     * is already clear is what a person pressing the same button twice means.
     *
     * @throws ProductNotInCompany
     */
    public function clear(Company $company, Uuid $productId, Uuid $establishmentId, ?Uuid $actorUserId): void
    {
        $this->replace($company, $productId, $establishmentId, [], $actorUserId);
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

    private function record(Company $company, Product $product, Uuid $establishmentId, string $action, ?Uuid $actorUserId): void
    {
        // Keyed on the PRODUCT, not on a home: what a screen reloads on is the product whose homes changed, and a
        // cleared home's own identifier names a row that no longer exists.
        $this->audit->record(new AuditEntry(
            self::ENTITY_TYPE,
            $product->getId(),
            $action,
            $actorUserId,
            ['establishmentId' => $establishmentId->toRfc4122()],
            companyId: $company->getId(),
        ));
    }
}
