<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Customers\Domain\CustomerSearch;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

final class InMemoryCustomers implements CustomerRepository
{
    /** @var list<Customer> */
    public array $customers = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->customers, static fn (Customer $c) => $c->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (Customer $a, Customer $b) => $a->getNumber() <=> $b->getNumber());

        return $mine;
    }

    /** Narrows as the database does; sorts by number only, which is all the unit tests ask for. */
    public function search(Uuid $companyId, CustomerSearch $search, PageRequest $page): Page
    {
        $found = array_values(array_filter($this->ofCompany($companyId), static function (Customer $c) use ($search): bool {
            $profile = $c->getProfile();
            $text = null === $search->text || str_contains(mb_strtolower(implode(' ', [$c->getNumber(), $profile->name, $profile->legalName, $profile->email, $profile->billingAddress->city])), mb_strtolower($search->text));

            return $text
                && (null === $search->kind || $profile->kind === $search->kind)
                && (null === $search->groupId || true === $c->getGroup()?->getId()->equals($search->groupId))
                && (null === $search->active || $c->isActive() === $search->active);
        }));

        return new Page(\array_slice($found, $page->offset(), $page->size), \count($found), $page);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Customer
    {
        foreach ($this->ofCompany($companyId) as $customer) {
            if ($customer->getId()->equals($id)) {
                return $customer;
            }
        }

        return null;
    }

    public function ofNumberInCompany(string $number, Uuid $companyId): ?Customer
    {
        foreach ($this->ofCompany($companyId) as $customer) {
            if ($customer->getNumber() === $number) {
                return $customer;
            }
        }

        return null;
    }

    public function countInGroup(Uuid $groupId): int
    {
        return \count(array_filter($this->customers, static fn (Customer $c) => true === $c->getGroup()?->getId()->equals($groupId)));
    }

    public function save(Customer $customer): void
    {
        if (!\in_array($customer, $this->customers, true)) {
            $this->customers[] = $customer;
        }
    }
}
