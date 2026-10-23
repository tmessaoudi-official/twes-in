<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Quotes;

use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Module\Quotes\Application\Delivery\QuoteDeliveries;
use App\Module\Quotes\Application\Delivery\QuoteDeliveryNote;
use App\Module\Quotes\Application\Delivery\QuoteDeliveryNotes;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** Answers the quotes' `QuoteDeliveryNotes` port from this module's notes, by the quote lines their lines name. */
final readonly class NotesOfQuotes implements QuoteDeliveryNotes
{
    public function __construct(private DeliveryNoteRepository $notes)
    {
    }

    public function ofQuotes(Company $company, array $lineIdsByQuote): array
    {
        $quoteOfLine = [];
        foreach ($lineIdsByQuote as $quoteId => $ids) {
            foreach ($ids as $id) {
                $quoteOfLine[$id->toRfc4122()] = (string) $quoteId;
            }
        }
        if ([] === $quoteOfLine) {
            return [];
        }
        $lineIds = array_map(static fn (string $id): Uuid => Uuid::fromString($id), array_keys($quoteOfLine));
        $onNotes = $this->notes->quoteLineQuantities($company->getId(), $lineIds);
        $delivered = $this->notes->quoteLineQuantities($company->getId(), $lineIds, deliveredOnly: true);
        $notesOf = [];
        foreach ($this->notes->delivering($company->getId(), $lineIds) as $note) {
            foreach (self::quotesOf($note, $quoteOfLine) as $quoteId) {
                $notesOf[$quoteId][] = new QuoteDeliveryNote($note->getId()->toRfc4122(), $note->getNumber(), $note->getStatus()->value);
            }
        }

        $deliveries = [];
        foreach ($lineIdsByQuote as $quoteId => $ids) {
            $key = (string) $quoteId;
            if (!isset($notesOf[$key])) {
                continue;
            }
            $own = array_flip(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids));
            $deliveries[$key] = new QuoteDeliveries($notesOf[$key], array_intersect_key($onNotes, $own), array_intersect_key($delivered, $own));
        }

        return $deliveries;
    }

    /**
     * @param array<string, string> $quoteOfLine
     *
     * @return list<string> the quotes the note's lines deliver, each once
     */
    private static function quotesOf(DeliveryNote $note, array $quoteOfLine): array
    {
        $quotes = [];
        foreach ($note->getLines() as $line) {
            $source = $line->getSourceQuoteLineId()?->toRfc4122();
            if (null !== $source && isset($quoteOfLine[$source])) {
                $quotes[] = $quoteOfLine[$source];
            }
        }

        return array_values(array_unique($quotes));
    }
}
