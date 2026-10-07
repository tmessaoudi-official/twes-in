<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Customers\Domain\Customer;
use App\Module\Invoices\Domain\Deduction;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceLineTax;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * A deposit invoice given back on another invoice (docs/fiscal FR.md and TN.md § 2b): one line per line of the deposit,
 * its net and the amount of each tax the deposit charged on it, written by the API from the issued deposit and never
 * from what a screen sends. A deposit is given back once, by one invoice that is not cancelled, while it is issued and
 * no issued credit note corrects it.
 */
final readonly class DepositDeductions
{
    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceTotals $totals,
        private DepositWording $wording,
        private ReadSetting $settings,
    ) {
    }

    /** The language the customer's documents are written in, which issuing prints them in too. */
    public function language(Company $company, Customer $customer): string
    {
        $language = $this->settings->value(new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId()), 'document.language');

        return \is_string($language) ? $language : 'fr';
    }

    /**
     * The lines giving the deposit back on `$by`, a document being written when null.
     *
     * @return list<InvoiceLineDetails>
     *
     * @throws InvalidInvoice on `deductsInvoiceId`
     */
    public function linesGivingBack(Company $company, Uuid $depositId, ?Invoice $by, string $language): array
    {
        $deposit = $this->invoices->ofIdInCompany($depositId, $company->getId())
            ?? throw new InvalidInvoice('deductsInvoiceId', 'No invoice of this company has this id.');
        $this->assertDeductible($company, $deposit, $by);
        $number = $deposit->getNumber() ?? throw new \LogicException('An issued deposit has a number.');
        $day = $deposit->getIssueDate() ?? throw new \LogicException('An issued deposit has an issue day.');
        $description = $this->wording->deductionLine($language, $number, $day);
        $totals = $this->totals->of($deposit);

        $lines = [];
        foreach ($deposit->getLines() as $index => $line) {
            $worked = $totals->lines[$index] ?? throw new \LogicException('The deposit is totalled line by line.');
            $fixed = $line->getFixedFigures() ?? throw new \LogicException('An issued deposit fixed its lines.');
            $taxes = [];
            foreach ($line->getTaxes() as $position => $tax) {
                $taxes[$tax->getTaxComponent()->getCode()] = ($worked->taxes[$position] ?? throw new \LogicException('The deposit taxes its lines tax by tax.'))->amount;
            }
            // Its figures are worked out again from what issuing kept; they must be the ones it printed. Compared as
            // amounts: a currency of two decimals works out 120.83 where the line keeps 120.830.
            if (0 !== Decimal::of($worked->net)->compare(Decimal::of($fixed['net'])) || 0 !== Decimal::sum(array_values(array_map(Decimal::of(...), $taxes)))->compare(Decimal::of($fixed['tax']))) {
                throw new \LogicException(\sprintf('The deposit %s no longer works out to what it printed: it cannot be given back as charged.', $number));
            }
            $lines[] = new InvoiceLineDetails(
                null,
                $description,
                '1',
                $line->getUnit(),
                Decimal::format(Decimal::of($worked->net)->sub(Decimal::of($worked->documentDiscount)), InvoiceLineDetails::PRICE_DECIMALS),
                null,
                array_map(static fn (InvoiceLineTax $tax) => $tax->getTaxComponent(), $line->getTaxes()),
                deduction: new Deduction($deposit, $taxes),
            );
        }

        return $lines;
    }

    /**
     * Whether `$by`, or a document being written when null, may give the deposit back now.
     *
     * @throws InvalidInvoice on `deductsInvoiceId`
     */
    public function assertDeductible(Company $company, Invoice $deposit, ?Invoice $by): void
    {
        $reference = $deposit->getNumber() ?? $deposit->getId()->toRfc4122();
        if (!$deposit->isDeposit()) {
            throw new InvalidInvoice('deductsInvoiceId', \sprintf('The invoice %s is not a deposit invoice: only a deposit is given back on another invoice.', $reference));
        }
        if (!$deposit->isDeductible()) {
            throw new InvalidInvoice('deductsInvoiceId', \sprintf('The deposit %s is given back once it is issued, and not once a credit note corrects it.', $reference));
        }
        foreach ($this->invoices->givingBack($company->getId(), $deposit->getId()) as $other) {
            if (null === $by || !$other->getId()->equals($by->getId())) {
                throw new InvalidInvoice('deductsInvoiceId', \sprintf('The deposit %s is already given back by the invoice %s.', $reference, $other->getNumber() ?? $other->getId()->toRfc4122().', a draft'));
            }
        }
    }
}
