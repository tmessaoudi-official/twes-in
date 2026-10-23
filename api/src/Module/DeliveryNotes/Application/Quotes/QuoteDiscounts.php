<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Quotes;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\DeliveryNotes\Application\InvoicedLines;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The discount an invoice line drawn from a note gives when the note delivers an accepted quote: the customer accepted a
 * price and a discount, and is billed at both however many deliveries it takes. A rate is the quote's rate. An amount is
 * shared by quantity: once a part of the line is invoiced, the invoices of it give the amount in proportion to what they
 * bill, rounded to the currency, and the part that bills the last of the line gives what is left of it, so the parts
 * sum to the whole. Runs inside the caller's transaction, which holds the quotes' rows meanwhile.
 */
final readonly class QuoteDiscounts
{
    public function __construct(
        private DeliverableQuotes $quotes,
        private DeliveryNoteRepository $notes,
        private InvoicedLines $invoiced,
        private CurrencyScales $scales,
    ) {
    }

    /**
     * @param list<array{DeliveryNoteLine, string}> $parts each line to invoice with the quantity invoiced, in the
     *                                                     invoice's order
     *
     * @return array<string, array{?string, ?string}> the discount rate or amount by delivery note line id; a line that
     *                                                delivers no quote, or one with no discount, is absent
     */
    public function of(Company $company, array $parts): array
    {
        $sources = [];
        foreach ($parts as [$line]) {
            $source = $line->getSourceQuoteLineId();
            if (null !== $source) {
                $sources[$source->toRfc4122()] = $source;
            }
        }
        if ([] === $sources) {
            return [];
        }
        $quoteLines = [];
        foreach ($this->quotes->ofLines($company, array_values($sources), true) as $quote) {
            foreach ($quote->lines as $quoteLine) {
                $quoteLines[$quoteLine->id->toRfc4122()] = $quoteLine;
            }
        }
        $delivering = $this->notes->linesDelivering($company->getId(), array_values($sources));
        $all = array_merge(...array_values($delivering));
        $taken = $this->invoiced->taken($company->getId(), $all);
        $given = $this->invoiced->discounts($company->getId(), $all);
        $billed = [];
        $shared = [];
        foreach ($delivering as $quoteLineId => $noteLines) {
            $billed[$quoteLineId] = Decimal::sum(array_map(static fn (Uuid $id): \BcMath\Number => Decimal::of($taken[$id->toRfc4122()] ?? '0'), $noteLines));
            $shared[$quoteLineId] = Decimal::sum(array_map(static fn (Uuid $id): \BcMath\Number => Decimal::of($given[$id->toRfc4122()] ?? '0'), $noteLines));
        }
        $scale = $this->scales->of($company->getCurrency());

        $discounts = [];
        foreach ($parts as [$line, $quantity]) {
            $key = $line->getSourceQuoteLineId()?->toRfc4122();
            $quoteLine = null === $key ? null : ($quoteLines[$key] ?? null);
            if (null === $key || null === $quoteLine) {
                continue;
            }
            if (null !== $quoteLine->discountRate) {
                $discounts[$line->getId()->toRfc4122()] = [$quoteLine->discountRate, null];
                continue;
            }
            if (null === $quoteLine->discountAmount || 0 === Decimal::of($quoteLine->discountAmount)->compare(0)) {
                continue;
            }
            $whole = Decimal::of($quoteLine->discountAmount);
            $ordered = Decimal::of($quoteLine->quantity);
            $billed[$key] = ($billed[$key] ?? Decimal::zero())->add(Decimal::of($quantity));
            $due = $billed[$key]->compare($ordered) >= 0 ? $whole : Decimal::round($whole->mul($billed[$key])->div($ordered, 12), $scale);
            // A draft whose discount was raised by hand may already give more than is due so far: this part then gives none.
            $share = $due->sub($shared[$key] ?? Decimal::zero());
            $share = $share->compare(0) > 0 ? $share : Decimal::zero();
            $shared[$key] = ($shared[$key] ?? Decimal::zero())->add($share);
            $discounts[$line->getId()->toRfc4122()] = [null, Decimal::format($share, 3)];
        }

        return $discounts;
    }
}
