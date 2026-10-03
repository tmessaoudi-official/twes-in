<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Application;

use App\Module\PriceLists\Domain\PriceListRepository;
use App\Module\Products\Application\CustomerPrice;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * What the customer screen may say of a promotion: a price list tied to no customer and no group, active and inside its
 * dates on the day asked, whose row prices a product under its shelf price. The row is the final tax-included price
 * counted as every document counts it, the minimum quantity it asks and the dates it runs between, and nothing else:
 * not the list's name, not what it is for. A list tied to a customer or a group never reaches this screen, because the
 * person looking at it is nobody in particular.
 */
final readonly class OpenPromotions
{
    public function __construct(private PriceListRepository $lists, private CustomerPrice $prices)
    {
    }

    /**
     * @param list<Uuid> $productIds
     *
     * @return list<array{productId: string, price: string, minQuantity: string, startsOn: string|null, endsOn: string|null}>
     */
    public function among(Company $company, array $productIds, \DateTimeImmutable $on): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $productIds);
        $rows = [];
        // No customer and no group asked for, so `applicable` answers exactly the lists made for everyone.
        foreach ($this->lists->applicable($company->getId(), $on, null, null) as $list) {
            foreach ($list->getItems() as $item) {
                $product = $item->getProduct();
                $shelf = $product->getDetails()->unitPriceNet;
                if (!\in_array($product->getId()->toRfc4122(), $wanted, true) || !is_numeric($shelf) || -1 !== new Number($item->getUnitPriceNet())->compare(new Number($shelf))) {
                    continue;
                }
                $rows[] = [
                    'productId' => $product->getId()->toRfc4122(),
                    'price' => $this->prices->of($product, 1, $item->getUnitPriceNet()),
                    'minQuantity' => $item->getMinQuantity(),
                    'startsOn' => $list->getValidFrom()?->format('Y-m-d'),
                    'endsOn' => $list->getValidTo()?->format('Y-m-d'),
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['productId'], (float) $a['minQuantity'], (float) $a['price']] <=> [$b['productId'], (float) $b['minQuantity'], (float) $b['price']]);

        return $rows;
    }
}
