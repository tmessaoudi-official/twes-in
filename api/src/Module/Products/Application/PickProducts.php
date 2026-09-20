<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductRepository;
use App\Tenancy\Domain\Company;

/**
 * The few products a person means while typing in a form (docs/SPEC.md § 7, 2026-09-17, ruling 3). A document form
 * used to be handed every active product before it drew a single line; a catalogue of twenty thousand makes that a
 * payload nobody can wait for and a list nobody can scroll, so what a form gets now is what the words find.
 *
 * It answers the same shape the options endpoints did, so a line starts from the same three facts: the unit it is
 * sold in, its price, and the taxes it carries.
 */
final readonly class PickProducts
{
    /** What a picker shows at once: enough to recognise the right one, few enough to read (§ 7, 2026-09-17). */
    public const int SHOWN = 20;

    public function __construct(private ProductRepository $products)
    {
    }

    /**
     * @return list<array{id: string, reference: string, name: string, unitId: string, unitPriceNet: string, defaultTaxComponentIds: list<string>}>
     */
    public function matching(Company $company, string $words, int $limit = self::SHOWN): array
    {
        return array_map(static fn (Product $product): array => [
            'id' => $product->getId()->toRfc4122(),
            'reference' => $product->getReference(),
            'name' => $product->getDetails()->name,
            'unitId' => $product->getUnit()->getId()->toRfc4122(),
            'unitPriceNet' => $product->getDetails()->unitPriceNet,
            'defaultTaxComponentIds' => $product->getDefaultTaxComponentIds(),
        ], $this->products->pick($company->getId(), $words, max(1, min($limit, self::SHOWN))));
    }
}
