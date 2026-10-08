<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The late fee a reminder stage charges (MON-15, CLI-14), drafted as an invoice of its own for the late invoice's
 * customer, from its establishment: the issued invoice is never touched, and the draft is never issued here, so a person
 * sees the fee before it becomes a document. The line carries no tax, since none is sourced for a late fee here; the
 * document carries what any of the customer's documents carries. Drafting goes through ManageInvoices, which audits it.
 */
final readonly class DraftLateFee
{
    /** The unit a fee counts in: one. */
    private const string ONE = 'C62';

    public function __construct(
        private ReadSetting $settings,
        private InvoiceRepository $invoices,
        private UnitRepository $units,
        private ManageInvoices $manage,
        private LateFeeWording $wording,
        private CurrencyScales $scales,
    ) {
    }

    /**
     * Drafts the fee the stage charges, if the company charges one there.
     *
     * @return array{invoiceId: Uuid, fee: string}|null null where nothing is charged, or the draft could not be written
     */
    public function handle(Company $company, Uuid $lateInvoiceId, int $stage, int $daysLate, string $amountDue): ?array
    {
        $context = new SettingContext($company);
        if (true !== $this->settings->value($context, LateFeeSettings::ENABLED)) {
            return null;
        }
        $tiers = $this->settings->value($context, LateFeeSettings::TIERS);
        $fee = LateFeeSettings::feeFor(\is_string($tiers) ? $tiers : '', $stage, $amountDue, $this->scales->of($company->getCurrency()));
        if (null === $fee) {
            return null;
        }

        $late = $this->invoices->ofIdInCompany($lateInvoiceId, $company->getId());
        $unit = $this->units->ofCodeInCompany(self::ONE, $company->getId());
        if (null === $late || null === $unit) {
            return null;
        }
        $customer = $late->getCustomer();
        $language = $this->settings->value(new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId()), 'document.language');
        $description = $this->wording->lateFeeLine(\is_string($language) ? $language : 'fr', $late->getNumber() ?? '', $stage, $daysLate);

        try {
            $line = new InvoiceLineDetails(null, $description, '1', $unit, $fee, null, []);
            $draft = $this->manage->createFromLines(
                $company,
                $late->getEstablishment(),
                $customer,
                new InvoiceHeader(),
                [$line],
                ['lateFeeOfInvoiceId' => $lateInvoiceId->toRfc4122(), 'stage' => $stage],
                null,
            );
        } catch (InvalidInvoice) {
            return null;
        }

        return ['invoiceId' => $draft->getId(), 'fee' => $fee];
    }
}
