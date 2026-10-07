<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Quotes;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Invoices\Application\DepositDeductions;
use App\Module\Invoices\Application\DepositWording;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Quotes\Application\QuoteDeposit;
use App\Module\Quotes\Application\QuoteInvoices;
use App\Module\Quotes\Application\QuoteInvoicingRefused;
use App\Module\Quotes\Domain\Quote;
use App\Module\Quotes\Domain\QuoteLine;
use App\Module\Quotes\Domain\QuoteLineTax;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the quotes' `QuoteInvoices` port through this module's own use case, audit and checks included: the draft
 * takes the quote's lines with the taxes they name today, its reference, its printed notes and its discount, and the
 * document taxes any new draft of the customer takes. A deposit is worked out from the quote as this module would
 * invoice it, and given back on the invoice of the whole (docs/fiscal FR.md and TN.md § 2b).
 */
final readonly class ManagedQuoteInvoices implements QuoteInvoices
{
    /** The unit a deposit line counts in: one, in UN/ECE Recommendation 20, which every preset ships. */
    private const string ONE = 'C62';

    public function __construct(
        private ManageInvoices $manage,
        private InvoiceRepository $invoices,
        private InvoiceTotals $totals,
        private CurrencyScales $scales,
        private DepositDeductions $deductions,
        private DepositWording $wording,
        private UnitRepository $units,
        private ClockInterface $clock,
    ) {
    }

    public function draftFrom(Quote $quote, ?Uuid $actorUserId): Uuid
    {
        $company = $quote->getCompany();
        $header = $quote->getHeader();
        try {
            $lines = $this->linesOf($quote);
            $language = $this->deductions->language($company, $quote->getCustomer());
            foreach ($this->invoices->depositsOfQuotes($company->getId(), [$quote->getId()]) as $deposit) {
                if (InvoiceStatus::Draft === $deposit->getStatus()) {
                    throw new QuoteInvoicingRefused('deposits', \sprintf('The deposit invoice drafted %s is still a draft: issue or cancel it before invoicing the quote.', $deposit->getCreatedAt()->format('Y-m-d')));
                }
                // A cancelled draft, a credited deposit or one another invoice already gives back is not given back here.
                if (!$deposit->isDeductible() || [] !== $this->invoices->givingBack($company->getId(), $deposit->getId())) {
                    continue;
                }
                $lines = [...$lines, ...$this->deductions->linesGivingBack($company, $deposit->getId(), null, $language)];
            }
            $invoice = $this->manage->createFromLines(
                $company,
                $quote->getEstablishment(),
                $quote->getCustomer(),
                new InvoiceHeader(customerReference: $header->customerReference, notesPrinted: $header->notesPrinted, discountAmount: $header->discountAmount),
                $lines,
                ['quoteId' => $quote->getId()->toRfc4122()],
                $actorUserId,
                $quote->getId(),
            );
        } catch (InvalidInvoice $refused) {
            throw new QuoteInvoicingRefused($refused->field, $refused->getMessage(), $refused);
        }

        return $invoice->getId();
    }

    public function depositFrom(Quote $quote, ?string $percentage, ?string $amount, ?Uuid $actorUserId): Uuid
    {
        $company = $quote->getCompany();
        $field = null === $percentage ? 'depositAmount' : 'depositPercentage';
        $scale = $this->scales->of($company->getCurrency());
        try {
            $whole = $this->wholeOf($quote);
            $tax = Decimal::of($whole->netAfterDocumentDiscount)->add(Decimal::of($whole->totalTax));
            if (null !== $amount && Decimal::of($amount)->compare($tax) >= 0) {
                throw new QuoteInvoicingRefused($field, \sprintf('A deposit is less than the quote, %s tax included.', Decimal::format($tax, $scale)));
            }
            if (null !== $amount && 0 !== Decimal::round(Decimal::of($amount), $scale)->compare(Decimal::of($amount))) {
                throw new QuoteInvoicingRefused($field, \sprintf('The currency %s has %d decimals.', $company->getCurrency(), $scale));
            }
            $share = null === $percentage ? Decimal::of((string) $amount)->div($tax, Decimal::WORKING_SCALE) : Decimal::of($percentage)->div(100, Decimal::WORKING_SCALE);

            // Each set of taxes the quote's lines carry, with what those lines come to after the quote's discount.
            $groups = [];
            foreach ($quote->getLines() as $index => $line) {
                $worked = $whole->lines[$index] ?? throw new \LogicException('The quote is totalled line by line.');
                $taxes = array_map(static fn (QuoteLineTax $tax): TaxComponent => $tax->getTaxComponent(), $line->getTaxes());
                $key = implode(',', array_map(static fn (TaxComponent $tax): string => $tax->getId()->toRfc4122(), $taxes));
                $groups[$key] ??= [$taxes, Decimal::zero()];
                $groups[$key][1] = $groups[$key][1]->add(Decimal::of($worked->net)->sub(Decimal::of($worked->documentDiscount)));
            }
            $groups = array_values($groups);
            $exact = array_map(static fn (array $group): Number => $group[1]->mul($share, Decimal::WORKING_SCALE), $groups);
            $nets = Decimal::allocate(Decimal::round(Decimal::sum($exact), $scale), $exact, $scale);

            $taken = Decimal::zero();
            foreach ($this->invoices->depositsOfQuotes($company->getId(), [$quote->getId()]) as $earlier) {
                if (InvoiceStatus::Cancelled !== $earlier->getStatus()) {
                    $taken = $taken->add(Decimal::of($this->totals->figures($earlier)->totalNet));
                }
            }
            $left = Decimal::of($whole->netAfterDocumentDiscount)->sub($taken);
            if (Decimal::sum($nets)->compare($left) > 0) {
                throw new QuoteInvoicingRefused($field, \sprintf('The deposits of a quote never go beyond it: %s net of tax is left.', Decimal::format($left, $scale)));
            }

            $unit = $this->units->ofCodeInCompany(self::ONE, $company->getId())
                ?? throw new QuoteInvoicingRefused('unitId', \sprintf('A deposit line counts in the unit %s, which the company does not have.', self::ONE));
            $description = $this->wording->depositLine($this->deductions->language($company, $quote->getCustomer()), $quote->getNumber() ?? '', $percentage);
            $lines = [];
            foreach ($groups as $index => [$taxes]) {
                if (0 === $nets[$index]->compare(0)) {
                    continue;
                }
                $lines[] = new InvoiceLineDetails(null, $description, '1', $unit, Decimal::format($nets[$index], InvoiceLineDetails::PRICE_DECIMALS), null, $taxes);
            }
            if ([] === $lines) {
                throw new QuoteInvoicingRefused($field, 'A deposit this small comes to nothing in the currency.');
            }

            $invoice = $this->manage->createFromLines(
                $company,
                $quote->getEstablishment(),
                $quote->getCustomer(),
                new InvoiceHeader(customerReference: $quote->getHeader()->customerReference),
                $lines,
                ['quoteId' => $quote->getId()->toRfc4122(), 'deposit' => true],
                $actorUserId,
                $quote->getId(),
                true,
            );
        } catch (InvalidInvoice $refused) {
            throw new QuoteInvoicingRefused($refused->field, $refused->getMessage(), $refused);
        }

        return $invoice->getId();
    }

    public function depositsOf(Company $company, array $quoteIds): array
    {
        $deposits = [];
        foreach ($this->invoices->depositsOfQuotes($company->getId(), $quoteIds) as $deposit) {
            $quoteId = $deposit->getQuoteId() ?? throw new \LogicException('A deposit drawn from a quote names it.');
            $deposits[$quoteId->toRfc4122()][] = new QuoteDeposit($deposit->getId()->toRfc4122(), $deposit->getNumber(), $deposit->getStatus()->value, $this->totals->figures($deposit)->total);
        }

        return $deposits;
    }

    public function stands(Company $company, Uuid $invoiceId): bool
    {
        $invoice = $this->invoices->ofIdInCompany($invoiceId, $company->getId());

        return null !== $invoice && InvoiceStatus::Cancelled !== $invoice->getStatus();
    }

    /**
     * The quote's lines as an invoice takes them.
     *
     * @return list<InvoiceLineDetails>
     */
    private function linesOf(Quote $quote): array
    {
        return array_map(static fn (QuoteLine $line): InvoiceLineDetails => new InvoiceLineDetails(
            $line->getProduct(),
            $line->getDescription(),
            $line->getQuantity(),
            $line->getUnit(),
            $line->getUnitPriceNet(),
            $line->getDiscountRate(),
            array_map(static fn (QuoteLineTax $tax) => $tax->getTaxComponent(), $line->getTaxes()),
        ), $quote->getLines());
    }

    /**
     * What the quote comes to, worked out as the invoice of the whole would be, with no fixed charge: a deposit is a
     * share of the operation, each invoice taking its own charges.
     *
     * @throws InvalidInvoice
     */
    private function wholeOf(Quote $quote): DocumentTotals
    {
        $whole = Invoice::create($quote->getCompany(), $quote->getEstablishment(), $quote->getCustomer(), new InvoiceHeader(discountAmount: $quote->getHeader()->discountAmount), $this->linesOf($quote), [], $this->clock->now());
        try {
            return $this->totals->of($whole);
        } catch (InvalidDocument $refused) {
            throw new InvalidInvoice('lines', $refused->getMessage());
        }
    }
}
