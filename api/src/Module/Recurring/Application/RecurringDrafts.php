<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/** Drafting an occurrence: a copy of the model as a new draft invoice, which the invoices write and audit. */
interface RecurringDrafts
{
    /**
     * The draft's id, or null when the model can no longer be copied (gone, or a document that is not copied).
     *
     * @param string $dueOn the occurrence's day, recorded with the draft as where it came from
     */
    public function draftFrom(Company $company, Uuid $modelInvoiceId, Uuid $recurringInvoiceId, string $dueOn): ?Uuid;
}
