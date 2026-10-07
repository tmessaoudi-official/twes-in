<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The few customers the quote form's picker offers. A port this module owns, answered by the customers', so neither
 * calls into the other.
 */
interface QuoteCustomers
{
    /** @return list<array{id: string, number: string, name: string, excludedFamilies: list<string>, defaultDiscountRate: string|null, defaultTaxComponentIds: list<string>}> */
    public function matching(Company $company, string $words): array;

    /**
     * @param list<Uuid> $ids
     *
     * @return list<array{id: string, number: string, name: string, excludedFamilies: list<string>, defaultDiscountRate: string|null, defaultTaxComponentIds: list<string>}>
     */
    public function byIds(Company $company, array $ids): array;
}
