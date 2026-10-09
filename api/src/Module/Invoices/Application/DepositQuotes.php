<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The quote a deposit invoice was drawn from, as issuing the deposit reads it. A port this module owns, answered by
 * the quotes', which keep the quotes, so neither calls into the other.
 */
interface DepositQuotes
{
    /**
     * What the quote comes to net of tax after its discount, at its currency's scale, the quote held until the caller's
     * transaction ends, so that two deposits of it are issued one after the other; null when it is not the company's.
     */
    public function heldNet(Company $company, Uuid $quoteId): ?string;
}
