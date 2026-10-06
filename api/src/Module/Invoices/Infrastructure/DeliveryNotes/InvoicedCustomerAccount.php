<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\DeliveryNotes;

use App\Module\Customers\Domain\Customer;
use App\Module\DeliveryNotes\Application\CustomerAccount;
use App\Module\Invoices\Application\CustomerCredit;
use App\Tenancy\Domain\Company;

/** Answers the delivery notes' `CustomerAccount` port out of this module's own reading of the account. */
final readonly class InvoicedCustomerAccount implements CustomerAccount
{
    public function __construct(private CustomerCredit $credit)
    {
    }

    public function limit(Company $company, Customer $customer): \BcMath\Number
    {
        return $this->credit->limit($company, $customer);
    }

    public function owed(Company $company, Customer $customer): \BcMath\Number
    {
        return $this->credit->owed($company, $customer);
    }
}
