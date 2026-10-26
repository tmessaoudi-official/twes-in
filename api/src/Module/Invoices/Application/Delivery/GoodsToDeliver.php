<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Delivery;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Model\InvoiceLine;
use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;

/**
 * Which goods of an invoice are delivered through notes after it is issued, said once for the notes and for the
 * invoice's own page: the lines of a product it billed itself, which left stock with it, never those a delivery note
 * handed over before (they are invoiced from that note) nor the deductions of a deposit; and what of each its issued
 * credit notes took back into stock, which is no longer owed.
 */
final class GoodsToDeliver
{
    /** Whether the invoice's goods are delivered through notes: an issued invoice of goods, neither a draft, a credit note nor a deposit. */
    public static function delivered(Invoice $invoice): bool
    {
        return InvoiceType::Invoice === $invoice->getType() && !$invoice->isDeposit() && self::issued($invoice);
    }

    /** @return list<InvoiceLine> its lines of goods it billed itself, in order; none for a credit note or a deposit */
    public static function lines(Invoice $invoice): array
    {
        return $invoice->goodsOfItsOwn();
    }

    /**
     * What the issued credit notes took back into stock, by the invoice line it came back from: the line a credit line
     * gives back, or, for a returned line naming none, the first of the invoice's goods of its product and unit, since
     * its goods came back from that sale all the same.
     *
     * @return array<string, string> decimal quantities by invoice line id; a line nothing came back from is absent
     */
    public static function returned(Invoice $invoice): array
    {
        $goods = self::lines($invoice);
        $returned = [];
        foreach ($invoice->getCorrections() as $credit) {
            if (!self::issued($credit)) {
                continue;
            }
            foreach ($credit->getLines() as $line) {
                $product = $line->getProduct();
                if (!$line->isReturned() || null === $product) {
                    continue;
                }
                $from = $line->getCreditedLineId()?->toRfc4122() ?? array_find($goods, static fn (InvoiceLine $sold): bool => true === $sold->getProduct()?->getId()->equals($product->getId()) && $sold->getUnit()->getId()->equals($line->getUnit()->getId()))?->getId()->toRfc4122();
                if (null === $from) {
                    continue;
                }
                $returned[$from] = Decimal::format(Decimal::of($returned[$from] ?? '0')->add(Decimal::of($line->getQuantity())), 3);
            }
        }

        return $returned;
    }

    private static function issued(Invoice $invoice): bool
    {
        return \in_array($invoice->getStatus(), [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true);
    }
}
