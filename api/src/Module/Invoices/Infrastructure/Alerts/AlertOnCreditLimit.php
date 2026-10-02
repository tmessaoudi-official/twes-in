<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Alerts;

use App\Module\Invoices\Application\TellCreditWatchers;
use App\Module\Invoices\Domain\InvoiceIssued;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Tenancy\Domain\CompanyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells the people who issue invoices when one takes its customer past their credit limit. The invoice is already
 * committed: an alert that cannot be written is logged, never thrown at the person who issued it.
 */
#[AsEventListener(event: InvoiceIssued::class)]
final readonly class AlertOnCreditLimit
{
    public function __construct(private InvoiceRepository $invoices, private CompanyRepository $companies, private TellCreditWatchers $tell, private LoggerInterface $logger)
    {
    }

    public function __invoke(InvoiceIssued $event): void
    {
        try {
            $company = $this->companies->ofId($event->companyId);
            $invoice = $this->invoices->ofIdInCompany($event->invoiceId, $event->companyId);
            if (null !== $company && null !== $invoice) {
                $this->tell->afterIssue($company, $invoice);
            }
        } catch (\Throwable $failure) {
            $this->logger->warning('The credit limit of the customer of invoice {number} was not checked: {reason}', ['number' => $event->number, 'reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
