<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Invoices;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\DeliveryNotes\Application\DeliveryNoteInput;
use App\Module\DeliveryNotes\Application\DeliveryNoteLineInput;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteHeader;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * « Bon de livraison » and « Livrer une partie » on an issued invoice: the goods it billed, already out of stock, leave
 * with draft notes on as many days as it takes, each naming per line the invoice line it delivers. Such a note moves no
 * stock and is never invoiced. What is left of a line is its quantity less what its credit notes took back and what the
 * notes that are not cancelled take, drafts included.
 */
final readonly class DeliverInvoices
{
    public function __construct(
        private DeliverableInvoices $invoices,
        private DeliveryNoteRepository $notes,
        private ManageDeliveryNotes $manage,
        private Transactions $transactions,
    ) {
    }

    /**
     * A draft note of the invoice's customer and establishment, for its reference, delivering every line for what is
     * left of it, or with `$quantities` only the lines named, each for its quantity.
     *
     * @param array<string, string>|null $quantities by invoice line id
     *
     * @throws DeliveredInvoiceNotFound
     * @throws DeliveryNoteTransitionRefused when the invoice is not an issued invoice of goods, or has nothing left
     * @throws InvalidDeliveryNote           on `quantities`, or as any draft is refused
     */
    public function draftFrom(Company $company, Uuid $invoiceId, ?array $quantities, ?Uuid $actorUserId): DeliveryNote
    {
        return $this->transactions->run(function () use ($company, $invoiceId, $quantities, $actorUserId): DeliveryNote {
            $invoice = $this->invoices->ofId($company, $invoiceId, true) ?? throw new DeliveredInvoiceNotFound();
            $refused = InvoiceDelivery::refusal($invoice);
            if (null !== $refused) {
                throw new DeliveryNoteTransitionRefused($refused);
            }
            $taken = $this->notes->invoiceLineQuantities($company->getId(), array_map(static fn (InvoiceLineToDeliver $line): Uuid => $line->id, $invoice->lines));
            $left = [];
            foreach ($invoice->lines as $line) {
                $left[$line->id->toRfc4122()] = InvoiceDelivery::left($line, $taken);
            }
            if ([] === $left) {
                throw new DeliveryNoteTransitionRefused(\sprintf('The invoice %s bills no goods of its own to deliver: its lines are services, deductions or goods its delivery notes already handed over.', InvoiceDelivery::reference($invoice)));
            }
            if (null !== $quantities) {
                foreach ($quantities as $lineId => $quantity) {
                    if (!isset($left[$lineId])) {
                        throw new InvalidDeliveryNote('quantities', \sprintf('The line %s is not among the goods the invoice %s billed.', $lineId, InvoiceDelivery::reference($invoice)));
                    }
                    if (!is_numeric($quantity) || Decimal::of($quantity)->compare(0) <= 0) {
                        throw new InvalidDeliveryNote('quantities', \sprintf('The quantity of the line %s is more than nothing.', $lineId));
                    }
                    if (Decimal::of($quantity)->compare($left[$lineId]) > 0) {
                        throw new InvalidDeliveryNote('quantities', \sprintf('Only %s of the line %s is left to deliver.', Decimal::format($left[$lineId]->compare(0) < 0 ? Decimal::zero() : $left[$lineId], 3), $lineId));
                    }
                }
            } elseif ([] === array_filter($left, static fn (\BcMath\Number $each): bool => $each->compare(0) > 0)) {
                throw new DeliveryNoteTransitionRefused(\sprintf('Nothing is left to deliver on the invoice %s: its credit notes and its notes that are not cancelled take all of it.', InvoiceDelivery::reference($invoice)));
            }

            $lines = [];
            foreach ($invoice->lines as $line) {
                $key = $line->id->toRfc4122();
                $quantity = null === $quantities ? ($left[$key]->compare(0) > 0 ? Decimal::format($left[$key], 3) : null) : ($quantities[$key] ?? null);
                if (null !== $quantity) {
                    $lines[] = new DeliveryNoteLineInput(null, null, $quantity, sourceInvoiceLineId: $line->id);
                }
            }

            return $this->manage->create($company, new DeliveryNoteInput(
                $invoice->customer->standingFor()->getId(),
                $invoice->establishment->getId(),
                new DeliveryNoteHeader(customerReference: $invoice->customerReference),
                $lines,
            ), $actorUserId, ['invoiceId' => $invoice->id->toRfc4122()]);
        });
    }
}
