<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Quotes;

use App\Module\Customers\Application\PickCustomers;
use App\Module\Quotes\Application\QuoteCustomers;
use App\Tenancy\Domain\Company;

/** Answers the quotes module's `QuoteCustomers` port out of this module. */
final readonly class PickedQuoteCustomers implements QuoteCustomers
{
    public function __construct(private PickCustomers $customers)
    {
    }

    public function matching(Company $company, string $words): array
    {
        return $this->customers->matching($company, $words);
    }

    public function byIds(Company $company, array $ids): array
    {
        return $this->customers->byIds($company, $ids);
    }
}
