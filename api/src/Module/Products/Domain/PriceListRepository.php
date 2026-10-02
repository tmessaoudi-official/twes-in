<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use Symfony\Component\Uid\Uuid;

interface PriceListRepository
{
    /** @return list<PriceList> one company's lists, by name */
    public function ofCompany(Uuid $companyId): array;

    /** Null for a list that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?PriceList;

    public function ofNameInCompany(string $name, Uuid $companyId): ?PriceList;

    /**
     * The lists that may price a sale on a day to this customer: active, valid that day, and for everyone, for the
     * customer's group or for the customer. Which of them wins is the resolver's to say.
     *
     * @return list<PriceList>
     */
    public function applicable(Uuid $companyId, \DateTimeImmutable $on, ?Uuid $customerId, ?Uuid $customerGroupId): array;

    public function save(PriceList $list): void;

    public function remove(PriceList $list): void;
}
