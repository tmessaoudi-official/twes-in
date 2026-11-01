<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Module\Invoices\Application\Chain\VerifyInvoiceChains;
use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\Race;
use App\Tests\Concurrency\Support\SaleRaceTestCase;
use App\Tests\Concurrency\Support\Steps\SellDocument;

/**
 * Numbering is gapless and given at issue (docs/SPEC.md § 3 Money): eight documents of one series issued at the same
 * moment take the series' first eight numbers, each once, none skipped, none refused for another's number, and the
 * issued documents' hash chain holds over them. Every series is taken through `AllocateNumber`'s lock on its row, held
 * until the document is stored: each workflow that numbers races here. A credit note and a deposit are issued by
 * `InvoiceWorkflow::issue` as an invoice is, their series' rows locked the same way.
 */
final class NumberingRaceTest extends SaleRaceTestCase
{
    private const int RACERS = 8;

    protected function saleWithoutStock(): string
    {
        return 'allow';
    }

    protected function onHand(): string
    {
        return '100';
    }

    public function testEightInvoicesIssuedAtOnceTakeOneThroughEight(): void
    {
        $this->numberedInOrder('invoice', 'invoices');
    }

    public function testEightDeliveryNotesValidatedAtOnceTakeOneThroughEight(): void
    {
        $this->numberedInOrder('delivery_note', 'delivery-notes');
    }

    public function testEightQuotesSentAtOnceTakeOneThroughEight(): void
    {
        $this->numberedInOrder('quote', 'quotes');
    }

    public function testEightPurchaseOrdersSentAtOnceTakeOneThroughEight(): void
    {
        $this->numberedInOrder('purchase_order', 'purchase-orders');
    }

    private function numberedInOrder(string $kind, string $resource): void
    {
        $partyId = 'purchase-orders' === $resource ? $this->vendor() : $this->customer();
        $documents = [];
        for ($racer = 0; $racer < self::RACERS; ++$racer) {
            $documents[] = $this->draft($resource, $partyId);
        }

        $outcomes = Race::run(SellDocument::class, self::RACERS, [
            'kinds' => array_fill(0, self::RACERS, $kind),
            'company' => $this->company->getId()->toRfc4122(),
            'documents' => $documents,
            'actor' => $this->sellerId,
        ]);

        $numbers = [];
        foreach ($outcomes as $outcome) {
            self::assertSame(Outcome::OK, $outcome->kind, $outcome->describe());
            self::assertIsString($outcome->value);
            $numbers[] = $outcome->value;
        }
        $sequence = array_map(static fn (string $number): int => (int) preg_replace('/^.*\D(\d+)$/', '$1', $number), $numbers);
        sort($sequence);
        self::assertSame(range(1, self::RACERS), $sequence, 'one through eight, each once: '.implode(', ', $numbers));
        // The racers wrote in processes of their own: this one still holds the drafts as it loaded them.
        $this->em()->clear();
        self::assertSame([], static::getContainer()->get(VerifyInvoiceChains::class)->of($this->company->getId()), 'the chain holds');
    }
}
