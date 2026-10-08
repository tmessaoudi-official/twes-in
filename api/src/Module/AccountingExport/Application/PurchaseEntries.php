<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Application;

use App\Tenancy\Domain\Company;

/** The expenses a company entered in its books for a period, by their own day: the port the expenses module answers. */
interface PurchaseEntries
{
    /** @return iterable<int, PurchaseEntry> by day */
    public function enteredIn(Company $company, JournalPeriod $period): iterable;
}
