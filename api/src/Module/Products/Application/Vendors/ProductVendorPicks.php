<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application\Vendors;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The vendors the product form offers while a person names who a product is bought from. A port this module owns,
 * answered by the vendors', so neither calls into the other.
 */
interface ProductVendorPicks
{
    /** Whether the company keeps a vendor book at all: while it does not, there is nothing to pick from. */
    public function available(Company $company): bool;

    /**
     * The company's active vendors whose number or name holds the words, the few a picker shows.
     *
     * @return list<ProductVendorPick>
     */
    public function matching(Company $company, string $words): array;

    /**
     * The company's vendors of these ids, retired ones too: what a product already names.
     *
     * @param list<Uuid> $ids
     *
     * @return list<ProductVendorPick>
     */
    public function ofIds(Company $company, array $ids): array;
}
