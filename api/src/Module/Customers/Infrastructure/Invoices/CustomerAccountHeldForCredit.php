<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Invoices;

use App\Module\Customers\Domain\Repository\CustomerRepository;
use App\Module\Invoices\Application\Credit\CustomerAccountsHeld;
use Symfony\Component\Uid\Uuid;

/** Answers the invoices' `CustomerAccountsHeld` port: the customer kept is held as its own writes hold it. */
final readonly class CustomerAccountHeldForCredit implements CustomerAccountsHeld
{
    public function __construct(private CustomerRepository $customers)
    {
    }

    public function hold(Uuid $companyId, Uuid $customerId): void
    {
        $kept = $this->customers->ofIdInCompany($customerId, $companyId)?->standingFor();
        if (null !== $kept) {
            $this->customers->lockedOfIdInCompany($kept->getId(), $companyId);
        }
    }
}
