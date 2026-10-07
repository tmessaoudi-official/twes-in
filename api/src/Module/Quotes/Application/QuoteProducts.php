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
 * The few products the quote form's picker offers. A port this module owns, answered by the products', so neither
 * calls into the other.
 */
interface QuoteProducts
{
    /** @return list<array{id: string, reference: string, name: string, unitId: string, unitPriceNet: string, defaultTaxComponentIds: list<string>, tracking: string}> */
    public function matching(Company $company, string $words): array;

    /**
     * @param list<Uuid> $ids
     *
     * @return list<array{id: string, reference: string, name: string, unitId: string, unitPriceNet: string, defaultTaxComponentIds: list<string>, tracking: string}>
     */
    public function byIds(Company $company, array $ids): array;
}
