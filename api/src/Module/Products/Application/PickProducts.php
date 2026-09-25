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
use Symfony\Component\Uid\Uuid;

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
     * @return list<array{id: string, reference: string, name: string, unitId: string, unitPriceNet: string, defaultTaxComponentIds: list<string>, tracking: string}>
     */
    public function matching(Company $company, string $words, int $limit = self::SHOWN): array
    {
        return array_map(self::row(...), $this->products->pick($company->getId(), $words, max(1, min($limit, self::SHOWN))));
    }

    /**
     * The same rows, for products a document already names — what a form opening one needs to show each line without
     * the catalogue. A RETIRED product is answered here and left out of `matching`: a line written last year still
     * names what was sold, but nobody puts it on a new one.
     *
     * @param list<Uuid> $ids
     *
     * @return list<array{id: string, reference: string, name: string, unitId: string, unitPriceNet: string, defaultTaxComponentIds: list<string>, tracking: string}>
     */
    public function byIds(Company $company, array $ids): array
    {
        return array_map(self::row(...), $this->products->ofIdsInCompany(\array_slice($ids, 0, self::SHOWN), $company->getId()));
    }

    /** @return array{id: string, reference: string, name: string, unitId: string, unitPriceNet: string, defaultTaxComponentIds: list<string>, tracking: string} */
    private static function row(Product $product): array
    {
        return [
            'id' => $product->getId()->toRfc4122(),
            'reference' => $product->getReference(),
            'name' => $product->getDetails()->name,
            'unitId' => $product->getUnit()->getId()->toRfc4122(),
            'unitPriceNet' => $product->getDetails()->unitPriceNet,
            'defaultTaxComponentIds' => $product->getDefaultTaxComponentIds(),
            // none, lot or serial: whether a line names the lot or serial handed over (docs/SPEC.md § 7, 2026-09-24 12:40 row 5).
            'tracking' => $product->getTracking()->value,
        ];
    }
}
