<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Products\Domain\PriceList;
use App\Module\Products\Domain\PriceListItem;
use App\Module\Products\Domain\PriceListRepository;
use App\Module\Products\Domain\Product;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * The unit price a line starts at (docs/SPEC.md § 7, 2026-09-20): a list for the customer wins over one for the
 * customer's group, which wins over one for everyone, and a product no applicable list prices keeps its shelf price.
 * Within the winning kind the row with the highest minimum the quantity reaches prices it; between two lists of the
 * same kind the lower price does, so the answer never depends on the order they were made in.
 */
final readonly class ResolveUnitPrice
{
    public function __construct(private PriceListRepository $lists, private CustomerRepository $customers)
    {
    }

    /** @param numeric-string $quantity */
    public function of(Product $product, ?Uuid $customerId, string $quantity, \DateTimeImmutable $on): ResolvedPrice
    {
        $company = $product->getCompany();
        $customer = null === $customerId ? null : $this->customers->ofIdInCompany($customerId, $company->getId());
        $groupId = $customer?->getGroup()?->getId();
        $lists = $this->lists->applicable($company->getId(), $on, $customer?->getId(), $groupId);

        $tiers = [
            static fn (PriceList $list): bool => null !== $customer && true === $list->getCustomerId()?->equals($customer->getId()),
            static fn (PriceList $list): bool => null !== $groupId && true === $list->getCustomerGroupId()?->equals($groupId),
            static fn (PriceList $list): bool => null === $list->getCustomerId() && null === $list->getCustomerGroupId(),
        ];
        foreach ($tiers as $inTier) {
            $best = null;
            foreach ($lists as $list) {
                if (!$inTier($list)) {
                    continue;
                }
                $row = $this->rowFor($list, $product, $quantity);
                if (null !== $row && (null === $best || -1 === new Number($row[1]->getUnitPriceNet())->compare(new Number($best[1]->getUnitPriceNet())))) {
                    $best = $row;
                }
            }
            if (null !== $best) {
                return new ResolvedPrice($best[1]->getUnitPriceNet(), $best[0]->getId(), $best[0]->getName(), $best[1]->getMinQuantity());
            }
        }

        return new ResolvedPrice($product->getDetails()->unitPriceNet, null, null, null);
    }

    /**
     * @param numeric-string $quantity
     *
     * @return array{PriceList, PriceListItem}|null the row of the product with the highest minimum the quantity reaches
     */
    private function rowFor(PriceList $list, Product $product, string $quantity): ?array
    {
        $row = null;
        foreach ($list->getItems() as $item) {
            if (!$item->getProduct()->getId()->equals($product->getId()) || 1 === new Number($item->getMinQuantity())->compare(new Number($quantity))) {
                continue;
            }
            if (null === $row || 1 === new Number($item->getMinQuantity())->compare(new Number($row->getMinQuantity()))) {
                $row = $item;
            }
        }

        return null === $row ? null : [$list, $row];
    }
}
