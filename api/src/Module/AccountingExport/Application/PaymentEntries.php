<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Application;

use App\Tenancy\Domain\Company;

/** The payments a company received on its invoices in a period: the port the sales module answers. */
interface PaymentEntries
{
    /** @return iterable<int, PaymentEntry> by day */
    public function receivedIn(Company $company, JournalPeriod $period): iterable;
}
