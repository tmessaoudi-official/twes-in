<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Invoices;

use App\Fiscal\Domain\Calculation\Decimal;
use Symfony\Component\Uid\Uuid;

/**
 * What decides whether an invoice is delivered through notes: only an issued invoice of goods is, since its goods left
 * stock and were billed when it was issued, and a note then only tells their way to the customer. A draft has billed
 * nothing and is invoiced from its notes instead; a credit note or a deposit invoice delivers no goods.
 */
final class InvoiceDelivery
{
    /** @return string|null why the invoice is not delivered through notes; null when it is */
    public static function refusal(InvoiceToDeliver $invoice): ?string
    {
        $reference = self::reference($invoice);

        return match (true) {
            !$invoice->sale => \sprintf('The document %s is a credit note or a deposit invoice: only an invoice of goods is delivered.', $reference),
            !$invoice->issued => \sprintf('The invoice %s is not issued: a draft bills nothing yet, so deliver its goods by notes first and invoice those notes.', $reference),
            default => null,
        };
    }

    /** @return array{string, array{reference: string}}|null the reason `refusal` gives, and its params */
    public static function refusalReason(InvoiceToDeliver $invoice): ?array
    {
        $reference = ['reference' => self::reference($invoice)];

        return match (true) {
            !$invoice->sale => ['not_goods_invoice', $reference],
            !$invoice->issued => ['invoice_not_issued', $reference],
            default => null,
        };
    }

    /** Its number, or its id while it has none. */
    public static function reference(InvoiceToDeliver $invoice): string
    {
        return $invoice->number ?? $invoice->id->toRfc4122();
    }

    public static function line(InvoiceToDeliver $invoice, Uuid $id): ?InvoiceLineToDeliver
    {
        return array_find($invoice->lines, static fn (InvoiceLineToDeliver $line): bool => $line->id->equals($id));
    }

    /**
     * What of the line is still owed to the customer: its quantity less what credit notes took back into stock and what
     * the notes that are not cancelled take of it.
     *
     * @param array<string, string> $taken by invoice line id
     */
    public static function left(InvoiceLineToDeliver $line, array $taken): \BcMath\Number
    {
        return Decimal::of($line->quantity)->sub(Decimal::of($line->returned))->sub(Decimal::of($taken[$line->id->toRfc4122()] ?? '0'));
    }
}
