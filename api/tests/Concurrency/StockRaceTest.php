<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\Race;
use App\Tests\Concurrency\Support\SaleRaceTestCase;
use App\Tests\Concurrency\Support\Steps\SellDocument;

/**
 * « Sortie sans stock enregistré » set to refuse, two cashiers selling the last unit at the same moment: one sale goes
 * through, the other is refused naming its short line, and the stock never goes below zero. The check and the stock it
 * reads must be one, under the stock's lock in the issuing transaction: read beforehand, both sales pass.
 */
final class StockRaceTest extends SaleRaceTestCase
{
    protected function saleWithoutStock(): string
    {
        return 'refuse';
    }

    protected function onHand(): string
    {
        return '1';
    }

    public function testTwoDeliveryNotesTakingTheLastUnitAtOnceSellItOnce(): void
    {
        $customerId = $this->customer();
        $this->raceForTheLastUnit(['delivery_note', 'delivery_note'], [$this->draft('delivery-notes', $customerId), $this->draft('delivery-notes', $customerId)]);
    }

    public function testTwoInvoicesTakingTheLastUnitAtOnceSellItOnce(): void
    {
        $customerId = $this->customer();
        $this->raceForTheLastUnit(['invoice', 'invoice'], [$this->draft('invoices', $customerId), $this->draft('invoices', $customerId)]);
    }

    /**
     * Two series, so no number's lock lines them up: a note and an invoice of the same goods are held apart only by the
     * stock's own lock, under which the shortfall is read.
     */
    public function testADeliveryNoteAndAnInvoiceTakingTheLastUnitAtOnceSellItOnce(): void
    {
        $customerId = $this->customer();
        $this->raceForTheLastUnit(['delivery_note', 'invoice'], [$this->draft('delivery-notes', $customerId), $this->draft('invoices', $customerId)]);
    }

    /**
     * @param list<string> $kinds
     * @param list<string> $documents
     */
    private function raceForTheLastUnit(array $kinds, array $documents): void
    {
        $outcomes = Race::run(SellDocument::class, 2, ['kinds' => $kinds, 'company' => $this->company->getId()->toRfc4122(), 'documents' => $documents, 'actor' => $this->sellerId]);

        $kinds = array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes);
        sort($kinds);
        self::assertSame([Outcome::ERROR, Outcome::OK], $kinds, implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe(), $outcomes)));
        foreach ($outcomes as $outcome) {
            if (Outcome::ERROR === $outcome->kind) {
                self::assertStringContainsString('ShortOfStock', $outcome->message, 'the sale left over is refused for its short line');
            }
        }
        self::assertSame(['0.000'], array_column($this->levels(), 'quantity'), 'the last unit sold once, the stock never below zero');
    }
}
