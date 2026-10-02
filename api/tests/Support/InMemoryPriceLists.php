<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\PriceLists\Domain\PriceList;
use App\Module\PriceLists\Domain\PriceListRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryPriceLists implements PriceListRepository
{
    /** @var list<PriceList> */
    public array $lists = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->lists, static fn (PriceList $l) => $l->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (PriceList $a, PriceList $b) => $a->getName() <=> $b->getName());

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?PriceList
    {
        foreach ($this->ofCompany($companyId) as $list) {
            if ($list->getId()->equals($id)) {
                return $list;
            }
        }

        return null;
    }

    public function ofNameInCompany(string $name, Uuid $companyId): ?PriceList
    {
        foreach ($this->ofCompany($companyId) as $list) {
            if ($list->getName() === $name) {
                return $list;
            }
        }

        return null;
    }

    /** Narrows as the database does: active, valid that day, and for everyone, the customer's group or the customer. */
    public function applicable(Uuid $companyId, \DateTimeImmutable $on, ?Uuid $customerId, ?Uuid $customerGroupId): array
    {
        $day = $on->format('Y-m-d');

        return array_values(array_filter($this->ofCompany($companyId), static function (PriceList $list) use ($day, $customerId, $customerGroupId): bool {
            if (!$list->isActive()
                || (null !== $list->getValidFrom() && $list->getValidFrom()->format('Y-m-d') > $day)
                || (null !== $list->getValidTo() && $list->getValidTo()->format('Y-m-d') < $day)) {
                return false;
            }
            $forCustomer = $list->getCustomerId();
            $forGroup = $list->getCustomerGroupId();

            return (null === $forCustomer && null === $forGroup)
                || (null !== $customerId && true === $forCustomer?->equals($customerId))
                || (null !== $customerGroupId && true === $forGroup?->equals($customerGroupId));
        }));
    }

    public function save(PriceList $list): void
    {
        if (!\in_array($list, $this->lists, true)) {
            $this->lists[] = $list;
        }
    }

    public function remove(PriceList $list): void
    {
        $this->lists = array_values(array_filter($this->lists, static fn (PriceList $l) => $l !== $list));
    }
}
