<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Statement;

use Symfony\Component\Uid\Uuid;

/**
 * The customers whose documents make up a customer's account: the customer itself and every customer merged into it,
 * whose invoices and payments are the one kept now. A port this module owns, answered by the customers'.
 */
interface MergedCustomers
{
    /** @return list<string> as RFC 4122, the customer first; empty when the company has no customer by this id */
    public function accountOf(Uuid $companyId, Uuid $customerId): array;
}
