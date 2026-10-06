<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\DeliveryNoteStatus;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * What delivering a note would do to its customer's credit limit: what they owe today plus what of the note no issued
 * invoice has taken yet (that part is owed already), against the limit that applies to them. It warns and never refuses,
 * and only about a note still to deliver: a delivered, invoiced or cancelled one has nothing left to warn about. Goods
 * already delivered and not yet invoiced are not on the account, so they are not counted.
 */
final readonly class DeliveryNoteCredit
{
    public function __construct(
        private ManageDeliveryNotes $notes,
        private DeliveryNoteTotals $totals,
        private CustomerAccount $credit,
        private CurrencyScales $scales,
        private InvoiceRepository $invoices,
    ) {
    }

    /**
     * @return array{limit: string, owed: string, noteTotal: string, afterDelivery: string, over: bool}
     *
     * @throws DeliveryNoteNotFound
     * @throws InvalidDeliveryNote  when the note's lines cannot be totalled
     */
    public function of(Company $company, Uuid $noteId): array
    {
        $note = $this->notes->get($company, $noteId);
        $customer = $note->getCustomer();
        $limit = $this->credit->limit($company, $customer);
        $owed = $this->credit->owed($company, $customer);
        $lineIds = array_map(static fn (DeliveryNoteLine $line): Uuid => $line->getId(), $note->getLines());
        $taken = $this->invoices->invoicedQuantities($company->getId(), $lineIds, true);
        try {
            $total = Decimal::of($this->totals->of($note, $taken)->total);
        } catch (InvalidDocument|UnsupportedTaxCombination $refused) {
            throw new InvalidDeliveryNote('lines', $refused->getMessage());
        }
        $after = $owed->add($total);
        $pending = \in_array($note->getStatus(), [DeliveryNoteStatus::Draft, DeliveryNoteStatus::Validated], true);
        $scale = $this->scales->of($company->getCurrency());

        return [
            'limit' => Decimal::format($limit, $scale),
            'owed' => Decimal::format($owed, $scale),
            'noteTotal' => Decimal::format($total, $scale),
            'afterDelivery' => Decimal::format($after, $scale),
            'over' => $pending && $limit->compare(0) > 0 && $after->compare($limit) > 0,
        ];
    }
}
