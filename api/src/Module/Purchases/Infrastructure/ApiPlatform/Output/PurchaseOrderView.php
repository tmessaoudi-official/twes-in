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
use App\Module\Purchases\Application\OrderVendors;
use App\Module\Purchases\Application\Receivers\PurchaseReceiptSettings;
use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderResource;
use App\Settings\Application\ReadSetting;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * How every orders endpoint answers an order: its prices and costs only to whoever may read costs, its net amounts at
 * the company's currency scale, and how each ordered product is tracked today, read once for a whole page, so a receipt
 * knows which lines name a lot.
 */
final readonly class PurchaseOrderView
{
    public function __construct(private OrderProducts $products, private OrderVendors $vendors, private CompanyGuard $guard, private CurrencyScales $scales, private ReadSetting $settings)
    {
    }

    /** One order, with the company's « Reçu par » rule its receipts are recorded and put right under, read once. */
    public function of(PurchaseOrder $order): PurchaseOrderResource
    {
        $resource = $this->page([$order])[0];
        $resource->receiverRule = PurchaseReceiptSettings::receiverRule($this->settings, $order->getCompany())->value;

        return $resource;
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

        // An order names its vendor as it was saved or sent; the list shows the vendor standing for it now, the one kept
        // when it was merged away, while a sent order's printed vendor stays in its snapshot.
        $vendors = [];
        foreach ($orders as $order) {
            $vendors[$order->getVendorId()->toRfc4122()] = $order->getVendorId();
        }
        $standing = $this->vendors->standingFor($company, array_values($vendors));

        return array_map(static function (PurchaseOrder $order) use ($seesCost, $scale, $tracking, $standing): PurchaseOrderResource {
            $resource = PurchaseOrderResource::of($order, $seesCost, $scale, $tracking);
            $resource->vendorName = $standing[$order->getVendorId()->toRfc4122()]->name ?? $resource->vendorName;

            return $resource;
        }, $orders);
    }
}
