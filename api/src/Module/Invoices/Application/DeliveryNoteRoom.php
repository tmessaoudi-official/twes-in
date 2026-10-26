<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use Symfony\Component\Uid\Uuid;

/**
 * What a delivery note line leaves to an invoice: what it delivered less what the company's other invoices that are not
 * cancelled take of it, nothing once its note was cancelled (docs/SPEC.md § 7, audit 2026-10-06 A-16). Checked when a
 * draft is revised and again when it is issued, since a draft put back from « Récemment supprimés » was written before
 * whatever invoiced or cancelled its notes meanwhile.
 */
final readonly class DeliveryNoteRoom
{
    public function __construct(private SourceDeliveryNoteLines $sourceLines, private InvoiceRepository $invoices)
    {
    }

    /**
     * @param list<Uuid> $ids  delivery note lines
     * @param bool       $held whether what is read must stay so until the transaction ends, for a write that relies on it
     *
     * @return array{array<string, SourceDeliveryNoteLine>, array<string, string>} the note lines of the company, and
     *                                                                             what each leaves to this invoice
     */
    public function left(Uuid $companyId, Invoice $invoice, array $ids, bool $held = false): array
    {
        if ([] === $ids) {
            return [[], []];
        }
        $sources = $held ? $this->sourceLines->lockedOfIds($ids, $companyId) : $this->sourceLines->ofIds($ids, $companyId);
        $elsewhere = $this->invoices->invoicedQuantities($companyId, $ids, false, $invoice->getId());
        $room = [];
        foreach ($sources as $id => $source) {
            $room[$id] = $source->cancelled ? '0.000' : Decimal::format(Decimal::of($source->quantity)->sub(Decimal::of($elsewhere[$id] ?? '0')), 3);
        }

        return [$sources, $room];
    }

    /**
     * Every line taken from a delivery note keeps the note line's product and takes no more than it leaves, read with
     * the notes held until the transaction ends.
     *
     * @param list<array{source: ?Uuid, product: ?Uuid, quantity: string}> $lines in the invoice's order
     *
     * @throws InvalidInvoice naming the line
     */
    public function assertWithin(Uuid $companyId, Invoice $invoice, array $lines): void
    {
        $ids = [];
        foreach ($lines as $line) {
            if (null !== $line['source']) {
                $ids[$line['source']->toRfc4122()] = $line['source'];
            }
        }
        [$sources, $room] = $this->left($companyId, $invoice, array_values($ids), true);
        foreach ($lines as $index => $line) {
            $id = $line['source']?->toRfc4122();
            if (null === $id || !isset($sources[$id], $room[$id])) {
                continue;
            }
            $source = $sources[$id];
            if ($source->cancelled) {
                throw (new InvalidInvoice('sourceDeliveryNoteLineId', 'The delivery note of this line was cancelled since the draft was written: its goods are back in stock and nothing of it is left to invoice. Remove the line.', 'source_note_cancelled'))->within("lines[$index]");
            }
            if (!(null === $source->productId ? null === $line['product'] : null !== $line['product'] && $source->productId->equals($line['product']))) {
                throw (new InvalidInvoice('productId', 'A line taken from a delivery note keeps the product the note delivered.', 'source_product'))->within("lines[$index]");
            }
            if (is_numeric($line['quantity']) && Decimal::of($line['quantity'])->compare(Decimal::of($room[$id])) > 0) {
                throw (new InvalidInvoice('quantity', \sprintf('Only %s of this delivery note line is left to invoice.', $room[$id]), 'left_to_invoice', ['quantity' => Decimal::format(Decimal::of($room[$id]), $source->unitDecimals)]))->within("lines[$index]");
            }
        }
    }
}
