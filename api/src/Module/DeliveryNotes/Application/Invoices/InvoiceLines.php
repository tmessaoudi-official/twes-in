<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Invoices;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\DeliveryNotes\Application\DeliveryNoteLineInput;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use Symfony\Component\Uid\Uuid;

/**
 * The lines of a note that deliver an issued invoice, checked against it. The invoice billed the goods and took them out
 * of stock when it was issued, so such a note moves no stock and is never invoiced: it delivers one invoice, of its
 * customer and establishment, carries nothing else, keeps each line's product, unit, price and taxes, and never more of
 * a line than the credit notes that took goods back and the notes that are not cancelled leave of it.
 */
final readonly class InvoiceLines
{
    public function __construct(private DeliverableInvoices $invoices, private DeliveryNoteRepository $notes)
    {
    }

    /**
     * The lines as the note will write them, with what they keep that may be retired since. `$current` is the draft
     * being revised, whose own lines are not counted as taken.
     *
     * @param list<DeliveryNoteLineInput> $lines
     *
     * @return array{list<DeliveryNoteLineInput>, array{products: list<string>, units: list<string>, taxes: list<string>}}
     *
     * @throws InvalidDeliveryNote
     */
    public function resolved(Company $company, array $lines, Customer $customer, Establishment $establishment, ?DeliveryNote $current, bool $locked): array
    {
        $kept = ['products' => [], 'units' => [], 'taxes' => []];
        $named = [];
        foreach ($lines as $index => $line) {
            if (null === $line->sourceInvoiceLineId) {
                continue;
            }
            if (null !== $line->sourceQuoteLineId) {
                throw new InvalidDeliveryNote("lines[$index].sourceInvoiceLineId", 'A line delivers a quote line or an invoice line, never both: the invoice already billed what the quote offered.');
            }
            $key = $line->sourceInvoiceLineId->toRfc4122();
            if (isset($named[$key])) {
                throw new InvalidDeliveryNote("lines[$index].sourceInvoiceLineId", 'An invoice line is delivered once on a note: change the quantity of the line already there.');
            }
            $named[$key] = $line->sourceInvoiceLineId;
        }
        if ([] === $named) {
            return [$lines, $kept];
        }

        $invoices = $this->invoices->ofLines($company, array_values($named), $locked);
        if (\count($invoices) > 1) {
            throw new InvalidDeliveryNote('lines', \sprintf('A delivery note delivers one invoice, and these lines come from %d: draw one note from each.', \count($invoices)));
        }
        $invoice = $invoices[0] ?? null;
        if (null !== $invoice) {
            $refused = InvoiceDelivery::refusal($invoice);
            if (null !== $refused) {
                throw new InvalidDeliveryNote('lines', $refused);
            }
            foreach ($lines as $index => $line) {
                if (null === $line->sourceInvoiceLineId) {
                    throw new InvalidDeliveryNote("lines[$index]", \sprintf('A note delivering the invoice %s carries its lines only, whose goods it billed: draw another note for anything else.', InvoiceDelivery::reference($invoice)));
                }
            }
            if (!$invoice->customer->standingFor()->getId()->equals($customer->standingFor()->getId())) {
                throw new InvalidDeliveryNote('customerId', \sprintf('A note delivering the invoice %s goes to its customer, %s.', InvoiceDelivery::reference($invoice), $invoice->customer->standingFor()->getProfile()->name));
            }
            if (!$invoice->establishment->getId()->equals($establishment->getId())) {
                throw new InvalidDeliveryNote('establishmentId', \sprintf('A note delivering the invoice %s leaves its establishment, %s.', InvoiceDelivery::reference($invoice), $invoice->establishment->getCode()));
            }
        }
        $taken = null === $invoice ? [] : $this->notes->invoiceLineQuantities($company->getId(), array_values($named), $current?->getId());

        $resolved = [];
        foreach ($lines as $index => $line) {
            $sourceId = $line->sourceInvoiceLineId ?? throw new \LogicException('Every line of a note delivering an invoice names its line.');
            $source = null === $invoice ? null : InvoiceDelivery::line($invoice, $sourceId);
            if (null === $source) {
                throw new InvalidDeliveryNote("lines[$index].sourceInvoiceLineId", 'No issued invoice of this company has this line among those it billed with their goods.');
            }
            try {
                $resolved[] = self::fromInvoice($line, $source, InvoiceDelivery::left($source, $taken));
            } catch (InvalidDeliveryNote $refused) {
                throw $refused->within("lines[$index]");
            }
            $kept['products'][] = $source->product->standingFor()->getId()->toRfc4122();
            $kept['units'][] = $source->unit->getId()->toRfc4122();
            foreach ($source->taxes as $tax) {
                $kept['taxes'][] = $tax->getId()->toRfc4122();
            }
        }

        return [$resolved, $kept];
    }

    /** @throws InvalidDeliveryNote */
    private static function fromInvoice(DeliveryNoteLineInput $line, InvoiceLineToDeliver $source, \BcMath\Number $left): DeliveryNoteLineInput
    {
        $product = $source->product->standingFor();
        if (null !== $line->productId && !$line->productId->equals($product->getId()) && !$line->productId->equals($source->product->getId())) {
            throw new InvalidDeliveryNote('productId', 'A line delivered from an invoice delivers the product the invoice billed.');
        }
        if (null !== $line->unitId && !$line->unitId->equals($source->unit->getId())) {
            throw new InvalidDeliveryNote('unitId', \sprintf('A line delivered from an invoice counts in the invoice\'s unit, %s.', $source->unit->getCode()));
        }
        if (null !== $line->unitPriceNet && (!is_numeric($line->unitPriceNet) || 0 !== Decimal::of($line->unitPriceNet)->compare(Decimal::of($source->unitPriceNet)))) {
            throw new InvalidDeliveryNote('unitPriceNet', \sprintf('A line delivered from an invoice keeps the price it billed, %s.', Decimal::format(Decimal::of($source->unitPriceNet), 4)));
        }
        $taxIds = array_map(static fn (TaxComponent $tax): string => $tax->getId()->toRfc4122(), $source->taxes);
        if (null !== $line->taxComponentIds) {
            $sent = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $line->taxComponentIds);
            if ([] !== array_diff($sent, $taxIds) || [] !== array_diff($taxIds, $sent)) {
                throw new InvalidDeliveryNote('taxComponentIds', 'A line delivered from an invoice carries the taxes the invoice charged.');
            }
        }
        if (is_numeric($line->quantity) && Decimal::of($line->quantity)->compare($left) > 0) {
            throw new InvalidDeliveryNote('quantity', \sprintf('Only %s of this invoice line is left to deliver: its credit notes and its other notes take the rest.', Decimal::format($left->compare(0) < 0 ? Decimal::zero() : $left, 3)));
        }

        return new DeliveryNoteLineInput(
            $product->getId(),
            '' === TypedText::trimmed($line->description ?? '') ? $source->description : $line->description,
            $line->quantity,
            $source->unit->getId(),
            $source->unitPriceNet,
            array_map(static fn (TaxComponent $tax): Uuid => $tax->getId(), $source->taxes),
            $line->lotCode,
            sourceInvoiceLineId: $source->id,
        );
    }
}
