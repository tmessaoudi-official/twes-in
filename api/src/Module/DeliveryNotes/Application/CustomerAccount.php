<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Module\Customers\Domain\Customer;
use App\Tenancy\Domain\Company;

/**
 * A customer's account as a delivery weighs it: the credit limit that applies to them and what they owe. A port this
 * module owns, answered by the invoices', which keep the account, so neither calls into the other (docs/SPEC.md § 7,
 * audit 2026-10-06 C-4).
 */
interface CustomerAccount
{
    /** What the customer may owe before a delivery warns; zero is no limit. */
    public function limit(Company $company, Customer $customer): \BcMath\Number;

    /** What the customer owes at the end of the company's today. */
    public function owed(Company $company, Customer $customer): \BcMath\Number;
}
