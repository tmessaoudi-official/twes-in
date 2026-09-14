<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Module\Products\Domain\ProductDetails;
use Symfony\Component\Uid\Uuid;

/** A product as it is written: its reference and details, and the unit, category and taxes it names by id. */
final readonly class ProductInput
{
    /**
     * @param list<Uuid>              $defaultTaxComponentIds
     * @param array<array-key, mixed> $customFields           values by the company's custom field keys, checked by the use case
     */
    public function __construct(
        public string $reference,
        public ProductDetails $details,
        public Uuid $unitId,
        public ?Uuid $categoryId,
        public array $defaultTaxComponentIds,
        public bool $isActive,
        public array $customFields = [],
    ) {
    }
}
