<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\Race;
use App\Tests\Concurrency\Support\SaleRaceTestCase;
use App\Tests\Concurrency\Support\Steps\ClaimSubmissionKey;
use App\Tests\Concurrency\Support\Steps\PayInvoice;
use App\Tests\Concurrency\Support\Steps\SellDocument;
use Symfony\Component\Uid\Uuid;

/**
 * One action sent twice at the same moment, by two tabs or two clicks, is made once. Under one `Idempotency-Key`, one
 * request holds the key and the others wait on it. Under two keys, the record's own lock decides: a draft issued twice
 * takes one number and the second is refused for being issued already, and an invoice paid in full twice takes one
 * payment, the second refused for being above what is still due.
 */
final class SubmissionRaceTest extends SaleRaceTestCase
{
    private const int RACERS = 6;

    protected function saleWithoutStock(): string
    {
        return 'allow';
    }

    protected function onHand(): string
    {
        return '100';
    }

    public function testSixRequestsClaimingOneKeyAtOnceLetOneHoldIt(): void
    {
        $outcomes = Race::run(ClaimSubmissionKey::class, self::RACERS, [
            'account' => $this->sellerId,
            'key' => Uuid::v4()->toRfc4122(),
            'company' => $this->company->getId()->toRfc4122(),
        ]);

        $answers = [];
        foreach ($outcomes as $outcome) {
            self::assertSame(Outcome::OK, $outcome->kind, $outcome->describe());
            $answers[] = $outcome->value;
        }
        sort($answers);
        self::assertSame(['held', 'held', 'held', 'held', 'held', 'won'], $answers);
    }

    public function testOneDraftIssuedTwiceAtOnceTakesOneNumber(): void
    {
        $draft = $this->draft('invoices', $this->customer());
        $outcomes = Race::run(SellDocument::class, 2, ['kinds' => ['invoice', 'invoice'], 'company' => $this->company->getId()->toRfc4122(), 'documents' => [$draft, $draft], 'actor' => $this->sellerId]);

        $kinds = array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes);
        sort($kinds);
        self::assertSame([Outcome::ERROR, Outcome::OK], $kinds, implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe(), $outcomes)));
        foreach ($outcomes as $outcome) {
            if (Outcome::ERROR === $outcome->kind) {
                self::assertStringContainsString('InvoiceNotDraft', $outcome->message, 'refused for being issued already');
            }
        }
        self::assertSame(1, $this->rows('SELECT count(*) FROM invoice WHERE company_id = ? AND number IS NOT NULL'), 'one invoice numbered');
        self::assertSame(1, $this->rows("SELECT count(*) FROM numbering_series WHERE company_id = ? AND document_type = 'invoice' AND is_default AND next_number = 2"), 'the series moved once: no number lost');
    }

    public function testAnInvoicePaidInFullTwiceAtOnceTakesOnePayment(): void
    {
        $invoice = $this->draft('invoices', $this->customer());
        static::getContainer()->get(InvoiceWorkflow::class)->issue($this->company, Uuid::fromString($invoice), null);
        $due = Rows::text($this->em()->getConnection()->fetchOne('SELECT amount_due FROM invoice WHERE id = ?', [$invoice]));

        $outcomes = Race::run(PayInvoice::class, 2, ['company' => $this->company->getId()->toRfc4122(), 'invoice' => $invoice, 'amount' => $due, 'day' => self::dayOf($this->company)]);

        $kinds = array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes);
        sort($kinds);
        self::assertSame([Outcome::ERROR, Outcome::OK], $kinds, implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe(), $outcomes)));
        foreach ($outcomes as $outcome) {
            if (Outcome::ERROR === $outcome->kind) {
                self::assertStringContainsString('at most what is still due', $outcome->message);
            }
        }
        self::assertSame(1, $this->rows('SELECT count(*) FROM payment WHERE invoice_id = ?', $invoice), 'one payment');
        self::assertSame('0.000', Rows::text($this->em()->getConnection()->fetchOne('SELECT amount_due FROM invoice WHERE id = ?', [$invoice])));
    }

    private function rows(string $sql, ?string $id = null): int
    {
        return Rows::int($this->em()->getConnection()->fetchOne($sql, [$id ?? $this->company->getId()->toRfc4122()]));
    }
}
