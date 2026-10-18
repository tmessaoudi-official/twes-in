<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\Output;

use App\Fiscal\Application\CurrencyScales;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Purchases\Application\OrderProducts;
use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * How every orders endpoint answers an order: its prices and costs only to whoever may read costs, its net amounts at
 * the company's currency scale, and how each ordered product is tracked today, read once for a whole page, so a receipt
 * knows which lines name a lot.
 */
final readonly class PurchaseOrderView
{
    public function __construct(private OrderProducts $products, private CompanyGuard $guard, private CurrencyScales $scales)
    {
    }

    public function of(PurchaseOrder $order): PurchaseOrderResource
    {
        return $this->page([$order])[0];
    }

    /**
     * @param list<PurchaseOrder> $orders one company's
     *
     * @return list<PurchaseOrderResource>
     */
    public function page(array $orders): array
    {
        if ([] === $orders) {
            return [];
        }
        $company = $orders[0]->getCompany();
        $seesCost = $this->guard->may($company, ProductPermission::COST_READ);
        $scale = $this->scales->of($company->getCurrency());
        $named = [];
        foreach ($orders as $order) {
            foreach ($order->getLines() as $line) {
                $named[$line->getProductId()->toRfc4122()] = $line->getProductId();
            }
        }
        $tracking = [];
        foreach ([] === $named ? [] : $this->products->ofIds($company, array_values($named)) as $product) {
            $tracking[$product->id->toRfc4122()] = $product->tracking;
        }

        return array_map(static fn (PurchaseOrder $order): PurchaseOrderResource => PurchaseOrderResource::of($order, $seesCost, $scale, $tracking), $orders);
    }
}
