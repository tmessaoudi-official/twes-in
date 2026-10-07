<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Domain\CustomerRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/** A customer's running account as it stands today (RunningAccount). */
final readonly class ReadCustomerAccount
{
    public function __construct(private CustomerRepository $customers, private RunningAccount $account)
    {
    }

    /** @throws CustomerNotFound */
    public function handle(Company $company, Uuid $customerId): CustomerAccountToday
    {
        $customer = $this->customers->ofIdInCompany($customerId, $company->getId()) ?? throw new CustomerNotFound();

        return $this->account->of($company, $customer);
    }
}
