<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure;

use App\Module\PriceLists\Application\ResolveUnitPrice;
use App\Module\PriceLists\Infrastructure\Module\PriceListsModule;
use App\Module\Products\Domain\Product;
use App\ModuleRegistry\Application\ModuleStates;
use BcMath\Number;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The price a document line of a product starts at when it is sent without one, as `GET .../products/{id}/price`
 * answers the screen: on the company's own day, and with the shelf price while the company has price lists off. A
 * quantity that is not a positive number is the line's to refuse, so it is priced at the shelf here rather than refused
 * twice. What this module answers the delivery notes' and the quotes' ports with.
 */
final readonly class StartingPrices
{
    public function __construct(private ResolveUnitPrice $resolve, private ModuleStates $modules, private ClockInterface $clock)
    {
    }

    public function of(Product $product, Uuid $customerId, string $quantity): string
    {
        $company = $product->getCompany();
        if (!is_numeric($quantity) || 1 !== preg_match('/^\d+(\.\d+)?$/', $quantity) || 1 !== new Number($quantity)->compare(0) || !$this->modules->isEnabled($company->getId(), PriceListsModule::KEY)) {
            return $product->getDetails()->unitPriceNet;
        }
        $day = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'));

        return $this->resolve->of($product, $customerId, $quantity, $day)->unitPriceNet;
    }
}
