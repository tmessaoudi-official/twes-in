<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Domain\CustomerCreditEntry;
use App\Module\Invoices\Domain\CustomerCreditRepository;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceFigures;
use App\Module\Invoices\Domain\InvoiceIssue;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceType;
use App\Settings\Application\DocumentFormats;
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
        private CustomerCreditRepository $credits,
        private DepositDeductions $deductions,
        private PartyIdentity $parties,
        private FiscalPresets $presets,
    ) {
    }

    /**
     * @throws InvoiceNotFound
     * @throws InvoiceNotDraft
     * @throws InvalidInvoice
     * @throws NoNumberingSeries    when the invoice's establishment numbers no document of its type
     * @throws InvalidNumbering     when the company's day comes before the month of the series' last number
     * @throws InvoiceNumberTaken   when another establishment of the company already gave the number
     * @throws PartyIdentityMissing when the seller or the customer cannot be named as the law asks
     * @throws InvalidInvoice       on `operationCategory` when the law asks what its operations are and neither a choice nor its lines say
     * @throws InvalidInvoice       on `amountDue` when a credit note comes to more than its invoice invoiced less its earlier credit notes;
     *                              on `excessTo` when part of it was already paid and it does not say where that goes
     */
    public function issue(Company $company, Uuid $id, ?Uuid $actorUserId, ?CreditExcessTo $excessTo = null): Invoice
    {
        $invoice = $this->transactions->run(function () use ($company, $id, $actorUserId, $excessTo): Invoice {
            // Held until this transaction ends and read as it stands, before the series: a request that read the draft
            // before another issued it is refused here, and takes no number.
            $invoice = $this->invoices->lockedOfIdInCompany($id, $company->getId()) ?? throw new InvoiceNotFound();
            $invoice->assertDraft('is issued');
            $type = $invoice->getType();
            if (InvoiceType::Invoice === $type) {
                $this->stillGivenBack($company, $invoice);
            }
            $customer = $invoice->getCustomer();
            // Before the series, as a mention is: an invoice that cannot name its parties takes no number.
            $this->parties->assertNamed($company, $invoice->getEstablishment(), $customer);
            $context = new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());
            $language = $this->settings->value($context, 'document.language');
            $language = \is_string($language) ? $language : 'fr';
            // Before the series and the mentions, which depend on it: a document that cannot say what its operations are takes no number.
            $fields = $this->presets->get($company->getFiscalPreset())->invoiceFields;
            $operations = $fields->operationCategory ? ($invoice->operations() ?? throw new InvalidInvoice('operationCategory', 'Say what this invoice\'s operations are: deliveries of goods, services or both. A line naming no product says nothing of it.')) : null;
            $vatOnDebits = $fields->offersVatOnDebits() ? $company->getProfile()->vatOnDebits : null;
            // Before the series: a document refused for a mention it cannot print takes no number.
            $mentions = $this->mentions->forIssue($company, $customer, $type, $language, $operations);
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

            $terms = $invoice->getHeader()->paymentTermsDays ?? $this->settings->value($context, 'document.payment_terms_days');
            $profile = $company->getProfile();
            $invoice->issue(
                new InvoiceIssue(
                    $allocated->number,
                    $allocated->issueDate,
                    \is_int($terms) ? $terms : 0,
                    $language,
                    $mentions->keys,
                    $profile->latePenaltyText,
                    $profile->invoiceFooterText,
                    $actorUserId,
                    DocumentFormats::print($this->settings, $context, $company),
                    $mentions->parameters,
                    $operations,
                    $vatOnDebits,
                ),
                fn (Invoice $issuing): InvoiceFigures => null === $corrected ? $this->totals->issued($issuing) : $corrected->fitsCredit($this->totals->issued($issuing)),
                $now = $this->clock->now(),
            );
            $excess = null === $corrected ? '0.000' : $corrected->creditExcess($invoice->getIssuedFigures() ?? throw new \LogicException('An issued credit note has its figures.'));
            $destination = Decimal::of($excess)->compare(0) > 0
                ? ($excessTo ?? throw new InvalidInvoice('excessTo', \sprintf('%s of this credit note was already paid: say whether it goes to the customer\'s credit balance or is refunded.', $excess)))
                : null;
            $this->invoices->save($invoice);
            $this->audit->record(new AuditEntry(ManageInvoices::ENTITY_TYPE, $invoice->getId(), self::ISSUED, $actorUserId, ['number' => $allocated->number], $company->getId()));
            if (null !== $corrected) {
                $corrected->credit($invoice, $now);
                $this->invoices->save($corrected);
                $changes = [
                    'creditNoteId' => $invoice->getId()->toRfc4122(),
                    'number' => $allocated->number,
                    'amount' => ltrim($invoice->getIssuedFigures()->amountDue ?? '', '-'),
                ];
                if (null !== $destination) {
                    $this->giveBack($corrected, $allocated->number, $excess, $destination, $now->setTimezone(new \DateTimeZone($company->getTimezone())), $actorUserId, $now);
                    $changes += ['excess' => $excess, 'excessTo' => $destination->value];
                }
                $this->audit->record(new AuditEntry(ManageInvoices::ENTITY_TYPE, $corrected->getId(), self::CREDITED, $actorUserId, $changes, $company->getId()));
            }

            return $invoice;
        });
        $this->events->publish(...$invoice->releaseEvents());

        return $invoice;
    }

    /**
     * The deposits a draft gives back may still be given back by it: each deposit is held until the issue commits, so
     * two invoices issued at once cannot both give it back, and one credited since the draft was written is refused.
     *
     * @throws InvalidInvoice
     */
    private function stillGivenBack(Company $company, Invoice $invoice): void
    {
        $deposits = [];
        foreach ($invoice->getLines() as $index => $line) {
            $deposit = $line->getDeduction()?->deposit;
            if (null === $deposit || isset($deposits[$deposit->getId()->toRfc4122()])) {
                continue;
            }
            $deposits[$deposit->getId()->toRfc4122()] = true;
            $held = $this->invoices->lockedOfIdInCompany($deposit->getId(), $company->getId()) ?? throw new \LogicException('A deposit given back is of the same company.');
            try {
                $this->deductions->assertDeductible($company, $held, $invoice);
            } catch (InvalidInvoice $refused) {
                throw $refused->within("lines[$index]");
            }
        }
    }

    /** The excess becomes the customer's: kept to their credit, or recorded as paid back the same day. */
    private function giveBack(Invoice $invoice, string $creditNoteNumber, string $amount, CreditExcessTo $to, \DateTimeImmutable $today, ?Uuid $actorUserId, \DateTimeImmutable $now): void
    {
        $customer = $invoice->getCustomer();
        $this->credits->save(CustomerCreditEntry::credited($customer, $invoice, $creditNoteNumber, $amount, $today, $actorUserId, $now));
        if (CreditExcessTo::Refund === $to) {
            $this->credits->save(CustomerCreditEntry::refunded($customer, $invoice, $creditNoteNumber, $amount, $today, $actorUserId, $now));
        }
    }
}
