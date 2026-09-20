<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

interface CustomerRepository
{
    /** @return list<Customer> one company's customers, by number */
    public function ofCompany(Uuid $companyId): array;

    /** @return Page<Customer> one page of the company's customers that the search finds, in its order */
    public function search(Uuid $companyId, CustomerSearch $search, PageRequest $page): Page;

    /**
     * The first few ACTIVE customers a person picking one in a form would mean, by number. It searches on the same
     * expression the list does, so the same index serves it, and it counts nothing: a picker asks again on every
     * keystroke, and a total over a large book of customers is the cost that buys nothing here (§ 7, 2026-09-17).
     *
     * @return list<Customer>
     */
    public function pick(Uuid $companyId, string $words, int $limit): array;

    /** Null for a customer that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Customer;

    public function ofNumberInCompany(string $number, Uuid $companyId): ?Customer;

    /** How many customers, active or not, belong to the group. */
    public function countInGroup(Uuid $groupId): int;

    public function save(Customer $customer): void;
}
