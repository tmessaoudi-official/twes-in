<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\DocumentNames;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Invoices\Application\Chain\SealInvoiceChains;
use App\Module\Invoices\Application\Credit\CreditExcessTo;
use App\Module\Invoices\Application\Deposit\DepositDeductions;
use App\Module\Invoices\Application\Deposit\DepositQuotes;
use App\Module\Invoices\Application\Print\InvoiceMentions;
use App\Module\Invoices\Application\Print\PartyIdentity;
use App\Module\Invoices\Application\Print\PartyIdentityMissing;
use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Invoices\Domain\Exception\InvoiceNotDraft;
use App\Module\Invoices\Domain\Model\CustomerCreditEntry;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Model\InvoiceLine;
use App\Module\Invoices\Domain\Repository\CustomerCreditRepository;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use App\Module\Invoices\Domain\ValueObject\ChainSeries;
use App\Module\Invoices\Domain\ValueObject\InvoiceFigures;
use App\Module\Invoices\Domain\ValueObject\InvoiceIssue;
use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use App\Settings\Application\DocumentFormats;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\DomainEvents;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An invoice or a credit note past its draft (docs/SPEC.md § 7, 2026-09-14): issued in the transaction that numbers it,
 * with the terms and language its customer's settings give and the mentions its company prints. What it records is
 * published once it is stored, and the issue is audited with its number. A credit note is issued only when it fits
 * what its invoice still has due, and takes itself off that in the same transaction.
 */
final readonly class InvoiceWorkflow implements DeclaresAuditActions
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
        private DepositQuotes $depositQuotes,
        private CurrencyScales $scales,
        private SealInvoiceChains $chains,
        private DocumentNames $names,
        private ExcludedTaxFamilies $excluded,
        private DeliveryNoteRoom $noteRoom,
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
     * @throws InvalidInvoice       on `lines[i]` when a line takes more of its delivery note than is left, or its note was cancelled
     * @throws InvalidInvoice       on `deposit` when a deposit invoice comes to more than its quote leaves once its other issued deposits are charged
     *                              or its quote was billed otherwise since: its whole invoice stands, or notes that are not cancelled deliver it
     * @throws InvalidInvoice       on `lines[i].taxComponentIds` or `documentTaxComponentIds` when the customer's or the company's
     *                              regime, changed since the draft was saved, no longer charges one of its taxes
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
                // Read again under the notes' lock: a draft put back from « Récemment supprimés » was written before
                // whatever invoiced or cancelled its notes meanwhile.
                $this->noteRoom->assertWithin($company->getId(), $invoice, array_map(static fn (InvoiceLine $line): array => ['source' => $line->getSourceDeliveryNoteLineId(), 'product' => $line->getProduct()?->getId(), 'quantity' => $line->getQuantity()], $invoice->getLines()));
            }
            if ($invoice->isDeposit()) {
                $this->withinItsQuote($company, $invoice);
            }
            $customer = $invoice->getCustomer();
            // A draft put back from « Récemment supprimés » was never moved by a merge made meanwhile: it would print
            // the duplicate the merge retired.
            if ($customer->isMergedAway()) {
                throw new InvalidInvoice('customerId', $customer->whySwitchedOff(), ...$customer->switchedOffReason());
            }
            // Before the series, as a mention is: an invoice that cannot name its parties takes no number.
            $this->parties->assertNamed($company, $invoice->getEstablishment(), $customer);
            $this->chargedByItsRegimes($company, $invoice);
            $context = new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());
            $language = $this->settings->text($context, 'document.language');
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
                // A credit note issued since this one was saved may have taken some of what it gives back.
                $this->totals->settleLineDiscounts($invoice);
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
                    $this->names->now($company, $language, array_map(static fn (InvoiceLine $line): Unit => $line->getUnit(), $invoice->getLines())),
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
            // Still holding the series: it takes the place after the last one issued, and whatever an earlier release
            // issued unsealed is chained before it.
            $this->chains->series(new ChainSeries($company->getId(), $invoice->getEstablishment()->getId(), $type));
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
     * Every tax the draft carries is still one its customer's regime and its company's charge: either may have changed
     * since the draft was saved, and the issue refuses rather than drop a tax, which would choose a fiscal answer for
     * the person issuing. A credit note gives back what its invoice charged under the regimes it was issued in.
     *
     * @throws InvalidInvoice
     */
    private function chargedByItsRegimes(Company $company, Invoice $invoice): void
    {
        $regime = $invoice->getCustomer()->getTaxRegime();
        $chargedBefore = $invoice->getCorrectedInvoice()?->chargedTaxIds() ?? [];
        foreach ($invoice->getLines() as $index => $line) {
            foreach ($line->getTaxes() as $tax) {
                if (\in_array($tax->getTaxComponent()->getId()->toRfc4122(), $chargedBefore, true)) {
                    continue;
                }
                $leftOutBy = $this->excluded->regimeLeavingOut($company, $regime, $tax->getTaxComponent()->getFamily());
                if (null !== $leftOutBy) {
                    throw (new InvalidInvoice('taxComponentIds', \sprintf('The %s regime does not charge %s.', $leftOutBy, $tax->getCode()), 'regime_excludes_tax', ['tax' => $tax->getCode()]))->within("lines[$index]");
                }
            }
        }
        foreach ($invoice->getDocumentTaxes() as $tax) {
            if (\in_array($tax->getTaxComponent()->getId()->toRfc4122(), $chargedBefore, true)) {
                continue;
            }
            $leftOutBy = $this->excluded->regimeLeavingOut($company, $regime, $tax->getTaxComponent()->getFamily());
            if (null !== $leftOutBy) {
                throw new InvalidInvoice('documentTaxComponentIds', \sprintf('The %s regime does not charge %s.', $leftOutBy, $tax->getCode()), 'regime_excludes_tax', ['tax' => $tax->getCode()]);
            }
        }
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

    /**
     * A deposit draft is edited like any other, so what drawing it checked is checked again as it is charged: its net is
     * at most what its quote comes to less what the quote's other issued deposits charged. A draft charges nothing yet
     * and is held to the same when it is issued in turn; the quote is held first, so two deposits of it are issued one
     * after the other and neither reads the other as it was before.
     *
     * @throws InvalidInvoice
     */
    private function withinItsQuote(Company $company, Invoice $deposit): void
    {
        $quoteId = $deposit->getQuoteId() ?? throw new \LogicException('A deposit is drawn from a quote.');
        $quote = $this->depositQuotes->heldNet($company, $quoteId) ?? throw new \LogicException('A deposit is drawn from a quote of its own company.');
        // Read again under the quote's hold: a draft put back from « Récemment supprimés » was counted by nothing that
        // billed the quote meanwhile, and issued it would bill what no invoice gives back.
        $billed = $this->depositQuotes->billedOtherwise($company, $quoteId);
        if (null !== $billed) {
            throw new InvalidInvoice('deposit', $billed);
        }
        $charged = Decimal::zero();
        foreach ($this->invoices->depositsOfQuotes($company->getId(), [$quoteId]) as $other) {
            if (!$other->getId()->equals($deposit->getId()) && !\in_array($other->getStatus(), [InvoiceStatus::Draft, InvoiceStatus::Cancelled], true)) {
                $charged = $charged->add(Decimal::of($this->totals->figures($other)->totalNet));
            }
        }
        $left = Decimal::of($quote)->sub($charged);
        if (Decimal::of($this->totals->figures($deposit)->totalNet)->compare($left) > 0) {
            throw new InvalidInvoice('deposit', \sprintf('The deposits of a quote never go beyond it: %s net of tax is left of it once its issued deposits are charged.', Decimal::format($left, $this->scales->of($company->getCurrency()))));
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

    public function auditActions(): array
    {
        return [
            self::CREDITED => new Recorded(ManageInvoices::ENTITY_TYPE, AuditScope::Company),
            self::ISSUED => new Recorded(ManageInvoices::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
