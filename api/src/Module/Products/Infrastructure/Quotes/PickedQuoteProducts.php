<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Quotes;

use App\Module\Products\Application\PickProducts;
use App\Module\Quotes\Application\QuoteProducts;
use App\Tenancy\Domain\Company;

/** Answers the quotes module's `QuoteProducts` port out of this module. */
final readonly class PickedQuoteProducts implements QuoteProducts
{
    public function __construct(private PickProducts $products)
    {
    }

    public function matching(Company $company, string $words): array
    {
        return $this->products->matching($company, $words);
    }

    public function byIds(Company $company, array $ids): array
    {
        return $this->products->byIds($company, $ids);
    }
}
