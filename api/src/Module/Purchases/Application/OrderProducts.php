<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The products an order's lines name. A port this module owns, answered by the products', so neither calls into the
 * other.
 */
interface OrderProducts
{
    /**
     * The company's active goods whose reference, name or barcode holds the words, the few a picker shows.
     *
     * @return list<OrderProduct>
     */
    public function matching(Company $company, string $words): array;

    /**
     * The company's products of these ids, retired ones too: what an order already names keeps naming it.
     *
     * @param list<Uuid> $ids
     *
     * @return list<OrderProduct>
     */
    public function ofIds(Company $company, array $ids): array;

    /**
     * The vendor's own code for those of these products it has one for, by product id: the product's supplier code
     * naming the vendor that enters one piece, the first in the product's code order when there are several. A code
     * entering a carton is left out, as a line counts the product's own unit.
     *
     * @param list<Uuid> $ids
     *
     * @return array<string, string>
     */
    public function vendorCodes(Company $company, Uuid $vendorId, array $ids): array;
}
