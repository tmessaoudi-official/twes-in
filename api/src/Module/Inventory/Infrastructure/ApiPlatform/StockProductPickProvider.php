<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Products\Application\PickProducts;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * The few stocked products a stock screen offers, under the stock permission (see the resource beside this).
 *
 * Whether stock is kept of a product is half a column and half a SETTING: goods only, and `article.stock_tracking`
 * on for that product, its category or the company. The first half is a WHERE clause; the second is a chain this
 * has to walk per product, so the words find a bounded WINDOW of goods and the setting is applied inside it.
 *
 * That window is the honest part: it is `SCANNED` goods, not the catalogue, so a company whose matching goods are
 * overwhelmingly untracked can be offered fewer than `PickProducts::SHOWN` — the answer is then "these, among the
 * first few hundred that match", never "these are all there are". Typing more words narrows it, which is what a
 * person does anyway. Making it exact would mean either the setting in SQL or reading every product, and reading
 * every product is precisely what this endpoint exists to stop.
 *
 * @implements ProviderInterface<StockProductPickResource>
 */
final readonly class StockProductPickProvider implements ProviderInterface
{
    /** How many matching goods the setting is walked over before the answer is cut short. */
    private const int SCANNED = 200;

    public function __construct(
        private CompanyGuard $guard,
        private ProductRepository $products,
        private KeepStock $stock,
    ) {
    }

    /** @return list<StockProductPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);

        $ids = Paging::uuids($operation, 'ids');
        if ([] !== $ids) {
            // What a movement names is answered whether or not stock is still kept of it: the movement happened.
            return array_map(StockProductPickResource::of(...), $this->products->ofIdsInCompany($ids, $company->getId()));
        }

        $matching = $this->products->pick($company->getId(), Paging::text($operation) ?? '', self::SCANNED, ProductKind::Goods);
        $kept = array_filter($matching, fn (Product $product): bool => $this->stock->tracked($product));

        return array_map(StockProductPickResource::of(...), \array_slice(array_values($kept), 0, PickProducts::SHOWN));
    }
}
