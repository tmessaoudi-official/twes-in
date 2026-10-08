<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/** The invoices a recurring invoice copies, read from the invoices, which answer this port. */
interface RecurringModels
{
    /** The company's invoice, or null where it has none of that id. */
    public function describe(Company $company, Uuid $invoiceId): ?RecurringModel;

    /**
     * Several at once, keyed by id; an id the company has no invoice of is left out.
     *
     * @param list<Uuid> $invoiceIds
     *
     * @return array<string, RecurringModel>
     */
    public function describeAll(Company $company, array $invoiceIds): array;
}
