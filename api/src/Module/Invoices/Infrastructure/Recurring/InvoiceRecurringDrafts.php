<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Recurring;

use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Module\Recurring\Application\RecurringDrafts;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * A recurring invoice's occurrence, drafted as a copy of its model by the invoices' own use case. A cancelled model is
 * not copied, though a person may still duplicate it by hand: cancelling the invoice a schedule copies says the sale
 * no longer repeats, and the schedule is paused rather than drafting what nobody sells any more.
 */
final readonly class InvoiceRecurringDrafts implements RecurringDrafts
{
    public function __construct(private ManageInvoices $invoices)
    {
    }

    public function draftFrom(Company $company, Uuid $modelInvoiceId, Uuid $recurringInvoiceId, string $dueOn): ?Uuid
    {
        try {
            if (InvoiceStatus::Cancelled === $this->invoices->get($company, $modelInvoiceId)->getStatus()) {
                return null;
            }

            return $this->invoices->draftFromModel($company, $modelInvoiceId, ['recurringInvoiceId' => $recurringInvoiceId->toRfc4122(), 'dueOn' => $dueOn])->getId();
        } catch (InvoiceNotFound|InvoiceTransitionRefused|InvalidInvoice) {
            return null;
        }
    }
}
