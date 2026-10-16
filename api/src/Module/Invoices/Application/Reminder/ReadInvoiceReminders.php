<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Reminder;

use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Domain\Model\InvoiceReminder;
use App\Module\Invoices\Domain\Repository\InvoiceReminderRepository;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** The reminder stages an invoice reached, the first first. */
final readonly class ReadInvoiceReminders
{
    public function __construct(private InvoiceRepository $invoices, private InvoiceReminderRepository $reminders)
    {
    }

    /**
     * @return list<InvoiceReminder>
     *
     * @throws InvoiceNotFound
     */
    public function handle(Company $company, Uuid $invoiceId): array
    {
        if (null === $this->invoices->ofIdInCompany($invoiceId, $company->getId())) {
            throw new InvoiceNotFound();
        }

        return $this->reminders->ofInvoice($company->getId(), $invoiceId);
    }
}
