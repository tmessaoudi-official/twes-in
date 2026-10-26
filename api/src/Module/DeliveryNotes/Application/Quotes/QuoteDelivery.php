<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Quotes;

use Symfony\Component\Uid\Uuid;

/**
 * Whether a quote is delivered through notes, and if not why, in one place for every path that draws a note from it.
 * Its goods leave stock with its notes and are billed through them, so a quote also invoiced as a whole would take its
 * stock out and bill it twice; and what an invoice of some of its notes cannot carry exactly (a discount on the whole, a
 * deposit to give back) keeps it whole: invoiced first, its goods are delivered from that invoice.
 */
final class QuoteDelivery
{
    /**
     * @return array{bool, string, string, string, array{reference: string}}|null whether it is a conflict with the quote's
     *                                                                            state rather than a refused field, the field, why,
     *                                                                            its reason and that reason's params; null when the
     *                                                                            quote is delivered through notes
     */
    public static function refusal(QuoteToDeliver $quote): ?array
    {
        $reference = self::reference($quote);

        return match (true) {
            !$quote->accepted => [true, 'quoteId', \sprintf('The quote %s is not accepted: only an accepted quote is delivered.', $reference), 'quote_not_accepted', ['reference' => $reference]],
            $quote->invoiced => [true, 'quoteId', \sprintf('The quote %s is on an invoice drafted from it as a whole: deliver its goods from that invoice, or cancel that draft first.', $reference), 'quote_invoiced_whole', ['reference' => $reference]],
            $quote->documentDiscount => [false, 'discountAmount', \sprintf('The quote %s has a discount on the whole, which an invoice of some of its deliveries cannot share out exactly: invoice the quote as a whole, then deliver from that invoice.', $reference), 'quote_document_discount', ['reference' => $reference]],
            $quote->deposits => [false, 'deposits', \sprintf('The quote %s has a deposit invoice, which only the invoice of the whole quote gives back: invoice the quote as a whole, then deliver from that invoice.', $reference), 'quote_has_deposit', ['reference' => $reference]],
            default => null,
        };
    }

    /** Its number, or its id while it has none. */
    public static function reference(QuoteToDeliver $quote): string
    {
        return $quote->number ?? $quote->id->toRfc4122();
    }

    public static function line(QuoteToDeliver $quote, Uuid $id): ?QuoteLineToDeliver
    {
        return array_find($quote->lines, static fn (QuoteLineToDeliver $line): bool => $line->id->equals($id));
    }
}
