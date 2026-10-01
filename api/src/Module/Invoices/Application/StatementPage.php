<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Module\Customers\Domain\CustomerSnapshot;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\SellerSnapshot;

/**
 * What a printed statement of account shows: the account over its period, who sends it and to whom, in the language
 * the customer's documents are written in and the company's date and number formats.
 */
final readonly class StatementPage
{
    /**
     * @param string $language     fr or en
     * @param string $dateFormat   the company's `presentation.date-format`, `auto` for the language's
     * @param string $numberFormat the company's `presentation.number-format`, `auto` for the language's
     */
    public function __construct(
        public Company $company,
        public CustomerStatement $statement,
        public CustomerSnapshot $customer,
        public SellerSnapshot $seller,
        public string $language,
        public string $dateFormat = 'auto',
        public string $numberFormat = 'auto',
    ) {
    }
}
