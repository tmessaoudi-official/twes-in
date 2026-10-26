<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Quotes;

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
 * « Livrer » and « Livrer une partie »: an accepted quote, the order itself (docs/SPEC.md § 7, 2026-09-20 00:45), is
 * delivered by draft notes on as many days as it takes, each naming per line the quote line it delivers, until nothing
 * is left. What is left of a line is its quantity less what the notes that are not cancelled take, drafts included.
 */
final readonly class DeliverQuotes
{
    public function __construct(
        private DeliverableQuotes $quotes,
        private DeliveryNoteRepository $notes,
        private ManageDeliveryNotes $manage,
        private Transactions $transactions,
    ) {
    }

    /**
     * A draft note of the quote's customer and establishment, for its reference, delivering every line for what is left
     * of it, or with `$quantities` only the lines named, each for its quantity.
     *
     * @param array<string, string>|null $quantities by quote line id
     *
     * @throws DeliveredQuoteNotFound
     * @throws DeliveryNoteTransitionRefused when the quote is not accepted, is on an invoice that stands, or has nothing left
     * @throws InvalidDeliveryNote           on `discountAmount` or `deposits`, on `quantities`, or as any draft is refused
     */
    public function draftFrom(Company $company, Uuid $quoteId, ?array $quantities, ?Uuid $actorUserId): DeliveryNote
    {
        return $this->transactions->run(function () use ($company, $quoteId, $quantities, $actorUserId): DeliveryNote {
            $quote = $this->quotes->ofId($company, $quoteId, true) ?? throw new DeliveredQuoteNotFound();
            $refused = QuoteDelivery::refusal($quote);
            if (null !== $refused) {
                throw $refused[0] ? new DeliveryNoteTransitionRefused($refused[2]) : new InvalidDeliveryNote(...\array_slice($refused, 1));
            }
            $left = $this->leftOf($company, $quote);
            $decimals = [];
            foreach ($quote->lines as $line) {
                $decimals[$line->id->toRfc4122()] = $line->unit->getDecimals();
            }
            if (null !== $quantities) {
                foreach ($quantities as $lineId => $quantity) {
                    if (!isset($left[$lineId])) {
                        throw new InvalidDeliveryNote("quantities[$lineId]", \sprintf('The line %s is not on the quote %s.', $lineId, QuoteDelivery::reference($quote)), 'unknown_choice');
                    }
                    if (!is_numeric($quantity) || Decimal::of($quantity)->compare(0) <= 0) {
                        throw new InvalidDeliveryNote("quantities[$lineId]", \sprintf('The quantity of the line %s is more than nothing.', $lineId), 'quantity_above_zero');
                    }
                    if (Decimal::of($quantity)->compare($left[$lineId]) > 0) {
                        throw new InvalidDeliveryNote("quantities[$lineId]", \sprintf('Only %s of the line %s is left to deliver.', Decimal::format($left[$lineId], 3), $lineId), 'left_to_deliver', ['quantity' => Decimal::format($left[$lineId], $decimals[$lineId])]);
                    }
                }
            } elseif ([] === array_filter($left, static fn (\BcMath\Number $each): bool => $each->compare(0) > 0)) {
                throw new DeliveryNoteTransitionRefused(\sprintf('Nothing is left to deliver on the quote %s: its notes that are not cancelled take all of it.', QuoteDelivery::reference($quote)));
            }

            $lines = [];
            foreach ($quote->lines as $line) {
                $key = $line->id->toRfc4122();
                $quantity = null === $quantities ? ($left[$key]->compare(0) > 0 ? Decimal::format($left[$key], 3) : null) : ($quantities[$key] ?? null);
                if (null !== $quantity) {
                    $lines[] = new DeliveryNoteLineInput(null, null, $quantity, sourceQuoteLineId: $line->id);
                }
            }

            return $this->manage->create($company, new DeliveryNoteInput(
                $quote->customer->standingFor()->getId(),
                $quote->establishment->getId(),
                new DeliveryNoteHeader(customerReference: $quote->customerReference),
                $lines,
            ), $actorUserId, ['quoteId' => $quote->id->toRfc4122()]);
        });
    }

    /**
     * What of the quote is delivered and left, line by line: what its notes that are not cancelled take (drafts
     * included), what of it the notes past their draft delivered, and the rest.
     *
     * @return list<array{lineId: string, quantity: string, onNotes: string, delivered: string, left: string}>
     *
     * @throws DeliveredQuoteNotFound
     */
    public function left(Company $company, Uuid $quoteId): array
    {
        $quote = $this->quotes->ofId($company, $quoteId, false) ?? throw new DeliveredQuoteNotFound();
        $ids = array_map(static fn (QuoteLineToDeliver $line): Uuid => $line->id, $quote->lines);
        $onNotes = $this->notes->quoteLineQuantities($company->getId(), $ids);
        $delivered = $this->notes->quoteLineQuantities($company->getId(), $ids, deliveredOnly: true);

        return array_map(static function (QuoteLineToDeliver $line) use ($onNotes, $delivered): array {
            $key = $line->id->toRfc4122();
            $taken = Decimal::of($onNotes[$key] ?? '0');

            return [
                'lineId' => $key,
                'quantity' => Decimal::format(Decimal::of($line->quantity), 3),
                'onNotes' => Decimal::format($taken, 3),
                'delivered' => Decimal::format(Decimal::of($delivered[$key] ?? '0'), 3),
                'left' => Decimal::format(Decimal::of($line->quantity)->sub($taken), 3),
            ];
        }, $quote->lines);
    }

    /** @return array<string, \BcMath\Number> what is left of each line, by quote line id */
    private function leftOf(Company $company, QuoteToDeliver $quote): array
    {
        $taken = $this->notes->quoteLineQuantities($company->getId(), array_map(static fn (QuoteLineToDeliver $line): Uuid => $line->id, $quote->lines));
        $left = [];
        foreach ($quote->lines as $line) {
            $key = $line->id->toRfc4122();
            $left[$key] = Decimal::of($line->quantity)->sub(Decimal::of($taken[$key] ?? '0'));
        }

        return $left;
    }
}
