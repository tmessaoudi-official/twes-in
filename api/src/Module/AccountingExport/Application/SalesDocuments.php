<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Application;

use App\Tenancy\Domain\Company;

/** The invoices and credit notes a company issued in a period: the port the sales module answers. */
interface SalesDocuments
{
    /** @return iterable<int, SalesDocument> by issue day, then number */
    public function issuedIn(Company $company, JournalPeriod $period): iterable;
}
