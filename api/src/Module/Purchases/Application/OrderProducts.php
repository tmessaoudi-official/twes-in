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
}
