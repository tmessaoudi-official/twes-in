<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductTracking;
use Symfony\Component\Uid\Uuid;

/** A product as it is written: its reference and details, the unit, category and taxes it names by id, its codes. */
final readonly class ProductInput
{
    /**
     * @param list<Uuid>              $defaultTaxComponentIds
     * @param array<array-key, mixed> $customFields           values by the company's custom field keys, checked by the use case
     * @param list<BarcodeInput>|null $barcodes               the codes it answers to, exactly these; null keeps them
     * @param ProductTracking|null    $tracking               how its stock is told apart; null keeps it, `none` on a new one
     * @param bool                    $seesCosts              whether the writer may read costs (product.cost.read): one who
     *                                                        may not neither sets a cost nor a supplier's code, and keeps
     *                                                        those stored
     * @param list<Uuid>|null         $vendorIds              the vendors it is bought from, exactly these and each
     *                                                        supplier code's vendor among them; null keeps them
     * @param ProductCheckInput|null  $check                  « À vérifier » as written; null keeps it
     * @param bool                    $unitNamed              whether the writer named the unit, which confirms it; a
     *                                                        file's row that named none was given its company's default
     * @param bool                    $awaitsCount            a new product whose stock nobody knows yet, until counted
     * @param bool|null               $priceReached           whether the price written is the one kept: false for a file's
     *                                                        price taxes included no net price reaches, kept at the
     *                                                        nearest, which leaves the product to check; null, a row
     *                                                        naming no price, keeps what the product says
     */
    public function __construct(
        public string $reference,
        public ProductDetails $details,
        public Uuid $unitId,
        public ?Uuid $categoryId,
        public array $defaultTaxComponentIds,
        public bool $isActive,
        public array $customFields = [],
        public ?array $barcodes = null,
        public ?ProductTracking $tracking = null,
        public bool $seesCosts = true,
        public ?array $vendorIds = null,
        public ?ProductCheckInput $check = null,
        public bool $unitNamed = true,
        public bool $awaitsCount = false,
        public ?bool $priceReached = true,
    ) {
    }
}
