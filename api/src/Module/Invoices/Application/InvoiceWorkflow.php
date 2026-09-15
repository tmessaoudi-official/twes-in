<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceFigures;
use App\Module\Invoices\Domain\InvoiceIssue;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceType;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\DomainEvents;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\InvalidNumbering;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An invoice or a credit note past its draft (docs/SPEC.md § 7, 2026-09-14): issued in the transaction that numbers it,
 * with the terms and language its customer's settings give and the mentions its company prints. What it records is
 * published once it is stored, and the issue is audited with its number. A credit note is issued only when it fits
 * what its invoice still has due, and takes itself off that in the same transaction.
 */
final readonly class InvoiceWorkflow
{
    public const string ISSUED = 'invoice.issued';
    public const string CREDITED = 'invoice.credited';

    public function __construct(
        private InvoiceRepository $invoices,
        private AllocateNumber $numbers,
        private Transactions $transactions,
        private InvoiceTotals $totals,
        private InvoiceMentions $mentions,
        private ReadSetting $settings,
        private DomainEvents $events,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvoiceNotFound
     * @throws InvoiceNotDraft
     * @throws InvalidInvoice
     * @throws NoNumberingSeries  when the invoice's establishment numbers no document of its type
     * @throws InvalidNumbering   when the company's day comes before the month of the series' last number
     * @throws InvoiceNumberTaken when another establishment of the company already gave the number
     * @throws InvalidInvoice     on `amountDue` when a credit note comes to more than its invoice still has due
     */
    public function issue(Company $company, Uuid $id, ?Uuid $actorUserId): Invoice
    {
        $invoice = $this->transactions->run(function () use ($company, $id, $actorUserId): Invoice {
            // Held until this transaction ends and read as it stands, before the series: a request that read the draft
            // before another issued it is refused here, and takes no number.
            $invoice = $this->invoices->lockedOfIdInCompany($id, $company->getId()) ?? throw new InvoiceNotFound();
            $invoice->assertDraft('is issued');
            $type = $invoice->getType();
            $allocated = $this->numbers->allocate($company, $invoice->getEstablishment(), $type->value);
            if ($this->invoices->numberTaken($company->getId(), $type, $allocated->number)) {
                throw new InvoiceNumberTaken(\sprintf('The number %s is already on another document of this company: give the %s series of establishment %s a format with {EST}, so that establishments number apart.', $allocated->number, $type->value, $invoice->getEstablishment()->getCode()));
            }

            // A credit note's invoice is held with the series: what it still has due cannot move under the check.
            $corrected = null;
            if (InvoiceType::CreditNote === $type) {
                $correctedId = $invoice->getCorrectedInvoice()?->getId() ?? throw new \LogicException('A credit note corrects an invoice.');
                $corrected = $this->invoices->lockedOfIdInCompany($correctedId, $company->getId()) ?? throw new \LogicException('A credit note corrects an invoice of its own company.');
            }

            $customer = $invoice->getCustomer();
            $context = new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());
            $terms = $invoice->getHeader()->paymentTermsDays ?? $this->settings->value($context, 'document.payment_terms_days');
            $language = $this->settings->value($context, 'document.language');
            $profile = $company->getProfile();
            $invoice->issue(
                new InvoiceIssue(
                    $allocated->number,
                    $allocated->issueDate,
                    \is_int($terms) ? $terms : 0,
                    \is_string($language) ? $language : 'fr',
                    $this->mentions->keys($company, $customer),
                    $profile->latePenaltyText,
                    $profile->invoiceFooterText,
                    $actorUserId,
                ),
                fn (Invoice $issuing): InvoiceFigures => null === $corrected ? $this->totals->issued($issuing) : $corrected->fitsCredit($this->totals->issued($issuing)),
                $now = $this->clock->now(),
            );
            $this->invoices->save($invoice);
            $this->audit->record(new AuditEntry(ManageInvoices::ENTITY_TYPE, $invoice->getId(), self::ISSUED, $actorUserId, ['number' => $allocated->number], $company->getId()));
            if (null !== $corrected) {
                $corrected->credit($invoice, $now);
                $this->invoices->save($corrected);
                $this->audit->record(new AuditEntry(ManageInvoices::ENTITY_TYPE, $corrected->getId(), self::CREDITED, $actorUserId, [
                    'creditNoteId' => $invoice->getId()->toRfc4122(),
                    'number' => $allocated->number,
                    'amount' => ltrim($invoice->getIssuedFigures()->amountDue ?? '', '-'),
                ], $company->getId()));
            }

            return $invoice;
        });
        $this->events->publish(...$invoice->releaseEvents());

        return $invoice;
    }
}
