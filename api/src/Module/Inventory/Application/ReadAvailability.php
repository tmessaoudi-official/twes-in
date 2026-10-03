<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * What the customer screen may say of stock: in or out, a yes or a no and never a quantity, for the goods whose stock
 * the company keeps, and only once the company has turned it on. The answer is the whole company's stock; the setting
 * is not per establishment because the settings have no establishment level and the screen names none.
 */
final readonly class ReadAvailability
{
    public function __construct(
        private StockMovementRepository $movements,
        private ProductRepository $products,
        private KeepStock $stock,
        private ReadSetting $settings,
    ) {
    }

    /**
     * @param list<Uuid> $productIds
     *
     * @return list<array{productId: string, inStock: bool}> empty while the company keeps the answer to itself
     */
    public function among(Company $company, array $productIds): array
    {
        if (true !== $this->settings->value(new SettingContext($company), CustomerScreenSettings::SHOW_STOCK)) {
            return [];
        }
        $kept = array_values(array_filter($this->products->ofIdsInCompany($productIds, $company->getId()), $this->stock->tracked(...)));
        $totals = $this->movements->totalsOf($company->getId(), array_map(static fn ($product): Uuid => $product->getId(), $kept));
        $rows = [];
        foreach ($kept as $product) {
            $id = $product->getId()->toRfc4122();
            $rows[] = ['productId' => $id, 'inStock' => 1 === new Number($totals[$id] ?? '0')->compare(0)];
        }

        return $rows;
    }
}
