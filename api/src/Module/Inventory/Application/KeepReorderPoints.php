<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Inventory\Domain\Exception\InvalidReorderPoint;
use App\Module\Inventory\Domain\Model\ProductReorderPoint;
use App\Module\Inventory\Domain\Repository\ProductReorderPointRepository;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The quantity at or under which each product is to be reordered, per establishment (docs/SPEC.md § 7, 2026-09-24
 * 11:40). Setting one where the establishment already has one changes it; clearing one means no alert there. The
 * points are shown on the product's page beside its form, so a change of them moves the product's version, which a
 * screen's write names: a point read before another person's change is refused rather than written over it.
 */
final readonly class KeepReorderPoints
{
    public const string ENTITY_TYPE = 'product_reorder_point';
    public const string SET = 'product_reorder_point.set';
    public const string CLEARED = 'product_reorder_point.cleared';

    public function __construct(
        private ProductReorderPointRepository $points,
        private EstablishmentRepository $establishments,
        private ProductRepository $products,
        private ProductsHeld $held,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /**
     * Every establishment of the company with the point this product has there, none where it has none: a screen
     * asks for each one, and a member who may revise a product is not necessarily one who may read the company's
     * establishments.
     *
     * @return list<array{Establishment, ProductReorderPoint|null}> by establishment code
     *
     * @throws ProductNotInCompany
     */
    public function of(Company $company, Uuid $productId): array
    {
        $this->product($company, $productId);
        $points = [];
        foreach ($this->points->ofProduct($productId, $company->getId()) as $point) {
            $points[$point->getEstablishment()->getId()->toRfc4122()] = $point;
        }
        $establishments = $this->establishments->ofCompany($company->getId());
        usort($establishments, static fn (Establishment $a, Establishment $b): int => strcmp($a->getCode(), $b->getCode()));

        return array_map(static fn (Establishment $establishment): array => [$establishment, $points[$establishment->getId()->toRfc4122()] ?? null], $establishments);
    }

    /**
     * `$version` is the product's, the one the point was read at; an import, which read nothing, passes none and
     * still holds the product, so two writes of its points never run side by side.
     *
     * @throws ProductNotInCompany
     * @throws InvalidReorderPoint
     * @throws RecordChanged
     */
    public function set(Company $company, Uuid $productId, Uuid $establishmentId, string $quantity, ?Uuid $actorUserId, ?int $version = null): ProductReorderPoint
    {
        return $this->transactions->run(function () use ($company, $productId, $establishmentId, $quantity, $actorUserId, $version): ProductReorderPoint {
            $product = $this->held->held($company, $productId, $version) ?? throw new ProductNotInCompany();
            $establishment = $this->establishments->ofIdInCompany($establishmentId, $company->getId())
                ?? throw new InvalidReorderPoint('establishmentId', 'No establishment of this company has this id.', 'unknown_choice');

            $existing = $this->points->ofProductInEstablishment($productId, $establishment->getId());
            if (null === $existing) {
                $point = ProductReorderPoint::of($product, $establishment, $quantity, $this->clock->now());
                $this->points->save($point);
                $this->held->changedBeside($product);
                $this->record($company, $point, self::SET, $actorUserId);

                return $point;
            }
            if ($existing->change($quantity, $this->clock->now())) {
                $this->points->save($existing);
                $this->held->changedBeside($product);
                $this->record($company, $existing, self::SET, $actorUserId);
            }

            return $existing;
        });
    }

    /**
     * Takes the reorder point away in that establishment; a product with none there is not an error. `$version` is the
     * product's, as for `set`.
     *
     * @return int the product's version the write left
     *
     * @throws ProductNotInCompany
     * @throws RecordChanged
     */
    public function clear(Company $company, Uuid $productId, Uuid $establishmentId, ?Uuid $actorUserId, ?int $version = null): int
    {
        $product = $this->transactions->run(function () use ($company, $productId, $establishmentId, $actorUserId, $version): Product {
            $product = $this->held->held($company, $productId, $version) ?? throw new ProductNotInCompany();
            $point = $this->points->ofProductInEstablishment($productId, $establishmentId);
            if (null === $point) {
                return $product;
            }
            $this->points->remove($point);
            $this->held->changedBeside($product);
            $this->record($company, $point, self::CLEARED, $actorUserId);

            return $product;
        });

        // Read once the transaction is stored: the version moves as the change is written.
        return $product->getVersion();
    }

    /** @throws ProductNotInCompany */
    private function product(Company $company, Uuid $productId): Product
    {
        return $this->products->ofIdInCompany($productId, $company->getId()) ?? throw new ProductNotInCompany();
    }

    private function record(Company $company, ProductReorderPoint $point, string $action, ?Uuid $actorUserId): void
    {
        // Keyed on the PRODUCT, as a home is: what a screen reloads on is the product, and a cleared point's own
        // identifier names a row that no longer exists.
        $this->audit->record(new AuditEntry(
            self::ENTITY_TYPE,
            $point->getProduct()->getId(),
            $action,
            $actorUserId,
            ['establishmentId' => $point->getEstablishment()->getId()->toRfc4122(), 'quantity' => self::CLEARED === $action ? null : $point->getQuantity()],
            companyId: $company->getId(),
        ));
    }
}
