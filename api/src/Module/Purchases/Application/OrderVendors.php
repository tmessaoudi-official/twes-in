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
 * The vendors an order names. A port this module owns, answered by the vendors', so neither calls into the other.
 */
interface OrderVendors
{
    /** The company's vendor of this id, retired or not; null when it has none. */
    public function ofId(Company $company, Uuid $id): ?OrderVendor;

    /**
     * The company's active vendors whose number or name holds the words, the few a picker shows.
     *
     * @return list<OrderVendor>
     */
    public function matching(Company $company, string $words): array;

    /**
     * The company's vendors of these ids, retired ones too: what a list or an order already names.
     *
     * @param list<Uuid> $ids
     *
     * @return list<OrderVendor>
     */
    public function ofIds(Company $company, array $ids): array;

    /**
     * The vendor standing for each of these vendors, keyed by the id asked: itself, or the vendor kept when it was
     * merged away, which lists show in its place.
     *
     * @param list<Uuid> $ids
     *
     * @return array<string, OrderVendor>
     */
    public function standingFor(Company $company, array $ids): array;

    /**
     * These vendors and every vendor merged into them: what a list narrowed by a vendor takes in.
     *
     * @param list<Uuid> $ids
     *
     * @return list<Uuid>
     */
    public function withMergedAway(Company $company, array $ids): array;
}
