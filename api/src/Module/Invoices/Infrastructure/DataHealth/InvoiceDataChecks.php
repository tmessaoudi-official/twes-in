<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\DataHealth\Infrastructure\SeriesGaps;
use App\DataHealth\Infrastructure\UnrecordedDocuments;
use App\Module\Invoices\Application\Chain\VerifyInvoiceChains;
use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Application\Statement\MergedCustomers;
use App\Shared\Infrastructure\Doctrine\Rows;
use BcMath\Number;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * What the issued invoices and credit notes must still say: their chains hold to their recorded ends, their figures add
 * up as issuing computed them, their numbers run without a gap in each series and period, what was paid and credited
 * settles them as settling decided, a customer's credit adds up, and the journal recorded each issue.
 */
final readonly class InvoiceDataChecks implements DeclaresDataChecks
{
    public const string CHAIN = 'invoices.chain';
    public const string ARITHMETIC = 'invoices.arithmetic';
    public const string LINES = 'invoices.lines';
    public const string NUMBERING = 'invoices.numbering';
    public const string CREDIT_NOTES = 'invoices.credit_notes';
    public const string SETTLEMENT = 'invoices.settlement';
    public const string CREDIT_BALANCE = 'invoices.credit_balance';
    public const string JOURNAL = 'invoices.journal';

    public function __construct(
        private VerifyInvoiceChains $chains,
        private Connection $connection,
        private SeriesGaps $series,
        private UnrecordedDocuments $unrecorded,
        private MergedCustomers $mergedCustomers,
    ) {
    }

    public function dataChecks(): array
    {
        return [self::CHAIN, self::ARITHMETIC, self::LINES, self::NUMBERING, self::CREDIT_NOTES, self::SETTLEMENT, self::CREDIT_BALANCE, self::JOURNAL];
    }

    public function findings(string $check): iterable
    {
        return match ($check) {
            self::CHAIN => $this->chain(),
            self::ARITHMETIC => $this->arithmetic(),
            self::LINES => $this->lines(),
            self::NUMBERING => $this->series->findings(self::NUMBERING, 'SELECT company_id, establishment_id, document_type, number, issue_date, id FROM invoice'),
            self::CREDIT_NOTES => $this->creditNotes(),
            self::SETTLEMENT => $this->settlement(),
            self::CREDIT_BALANCE => $this->creditBalance(),
            self::JOURNAL => $this->unrecorded->findings(self::JOURNAL, 'SELECT company_id, id, number FROM invoice', ManageInvoices::ENTITY_TYPE, InvoiceWorkflow::ISSUED),
            default => throw new \LogicException(\sprintf('No invoice data check is named %s.', $check)),
        };
    }

    /** @return iterable<DataFinding> */
    private function chain(): iterable
    {
        foreach ($this->chains->all() as $break) {
            yield new DataFinding(self::CHAIN, $break->series->companyId, \sprintf(
                '%s: %s %s of establishment %s, place %s',
                $break->reason,
                $break->series->type->value,
                $break->number ?? '-',
                $break->series->establishmentId->toRfc4122(),
                $break->position ?? '-',
            ));
        }
    }

    /**
     * The sums an issued document's figures are frozen from, each read again from its lines, taxes and payments. The
     * tax of each line is left out: it is rounded per line, the document's per rate, and the two may differ by design.
     * An invoice's due never goes below nothing: money paid beyond what a credit note left due is the customer's credit;
     * a credit note's is what it gives back, below nothing.
     *
     * @return iterable<DataFinding>
     */
    private function arithmetic(): iterable
    {
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            SELECT company_id, number, unequal FROM (
                SELECT i.company_id, i.number, array_remove(ARRAY[
                    CASE WHEN i.subtotal_net IS DISTINCT FROM COALESCE((SELECT SUM(l.line_net) FROM invoice_line l WHERE l.invoice_id = i.id), 0) THEN 'subtotal' END,
                    CASE WHEN i.total_net IS DISTINCT FROM i.subtotal_net - COALESCE(i.document_discount, 0) THEN 'net' END,
                    CASE WHEN i.total_tax IS DISTINCT FROM COALESCE((SELECT SUM((t->>'amount')::numeric) FROM jsonb_array_elements(i.tax_breakdown) t), 0) THEN 'tax' END,
                    CASE WHEN i.total_gross IS DISTINCT FROM i.total_net + i.total_tax + COALESCE((SELECT SUM((f->>'amount')::numeric) FROM jsonb_array_elements(i.fixed_taxes) f), 0) THEN 'gross' END,
                    CASE WHEN i.amount_due IS DISTINCT FROM CASE WHEN i.document_type = 'credit_note' THEN i.total_gross - COALESCE(i.withholding_amount, 0) - i.amount_paid - i.amount_credited
                        ELSE GREATEST(i.total_gross - COALESCE(i.withholding_amount, 0) - i.amount_paid - i.amount_credited, 0) END THEN 'due' END,
                    CASE WHEN i.amount_paid IS DISTINCT FROM COALESCE((SELECT SUM(p.amount) FROM payment p WHERE p.invoice_id = i.id), 0) THEN 'paid' END
                ], NULL) AS unequal
                FROM invoice i
                WHERE i.number IS NOT NULL
            ) checked
            WHERE cardinality(unequal) > 0
            ORDER BY company_id, number
            SQL);
        foreach ($rows as $row) {
            yield new DataFinding(self::ARITHMETIC, self::uuid($row['company_id']), \sprintf('%s does not add up: %s', self::text($row['number']), self::text($row['unequal'])));
        }
    }

    /**
     * What an issued line keeps: its gross is its net and its tax. Its net is taken before the document discount and
     * its tax after it (KI D5), so the lines add up to the document's net and tax only where no document discount was
     * given; there each line rounded its tax on its own, half a unit at most of the coarsest currency kept, two decimals.
     *
     * @return iterable<DataFinding>
     */
    private function lines(): iterable
    {
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            SELECT company_id, number, unequal FROM (
                SELECT i.company_id, i.number, array_remove(ARRAY[
                    CASE WHEN EXISTS (SELECT 1 FROM invoice_line l WHERE l.invoice_id = i.id AND l.line_gross IS DISTINCT FROM l.line_net + l.line_tax) THEN 'line' END,
                    CASE WHEN COALESCE(i.document_discount, 0) = 0
                          AND ABS(lines.gross - (i.total_net + i.total_tax)) > 0.005 * GREATEST(lines.count, 1) THEN 'lines' END
                ], NULL) AS unequal
                FROM invoice i
                CROSS JOIN LATERAL (SELECT COALESCE(SUM(l.line_gross), 0) AS gross, COUNT(*) AS count FROM invoice_line l WHERE l.invoice_id = i.id) lines
                WHERE i.number IS NOT NULL
            ) checked
            WHERE cardinality(unequal) > 0
            ORDER BY company_id, number
            SQL);
        foreach ($rows as $row) {
            yield new DataFinding(self::LINES, self::uuid($row['company_id']), \sprintf('%s does not add up: %s', self::text($row['number']), self::text($row['unequal'])));
        }
    }

    /**
     * What issuing a credit note took off its invoice: the invoice's credited amount is what its issued credit notes
     * come to, each the net to pay it corrects, and a credit note corrects an invoice of its own customer.
     *
     * @return iterable<DataFinding>
     */
    private function creditNotes(): iterable
    {
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            SELECT i.company_id, i.number, 'credited amount' AS unequal
            FROM invoice i
            WHERE i.document_type = 'invoice' AND i.number IS NOT NULL
              AND i.amount_credited IS DISTINCT FROM COALESCE((
                  SELECT SUM(ABS(c.amount_due)) FROM invoice c
                  WHERE c.corrects_invoice_id = i.id AND c.document_type = 'credit_note' AND c.number IS NOT NULL
              ), 0)
            UNION ALL
            -- A credit note of the whole invoice gives back each rate's tax as charged. Its lines' shares of a tied
            -- allocation may still differ from the invoice's (KI D6), which is why the lines are not compared.
            SELECT c.company_id, c.number, 'taxes by rate'
            FROM invoice c
            JOIN invoice i ON i.id = c.corrects_invoice_id
            WHERE c.document_type = 'credit_note' AND c.number IS NOT NULL AND c.total_net = -i.total_net
              AND EXISTS (
                  SELECT 1
                  FROM (SELECT t->>'code' AS code, SUM((t->>'amount')::numeric) AS amount FROM jsonb_array_elements(i.tax_breakdown) t GROUP BY 1) charged
                  FULL JOIN (SELECT t->>'code' AS code, SUM((t->>'amount')::numeric) AS amount FROM jsonb_array_elements(c.tax_breakdown) t GROUP BY 1) given ON given.code = charged.code
                  WHERE COALESCE(charged.amount, 0) <> -COALESCE(given.amount, 0)
              )
            UNION ALL
            SELECT c.company_id, COALESCE(c.number, 'a draft'), 'invoice corrected'
            FROM invoice c
            JOIN invoice i ON i.id = c.corrects_invoice_id
            WHERE c.document_type = 'credit_note' AND (c.customer_id <> i.customer_id OR i.document_type <> 'invoice')
            ORDER BY 1, 2
            SQL);
        foreach ($rows as $row) {
            yield new DataFinding(self::CREDIT_NOTES, self::uuid($row['company_id']), \sprintf('%s: its %s does not match its credit notes', self::text($row['number']), self::text($row['unequal'])));
        }
    }

    /**
     * Where a document stands follows from what was paid, credited and is still due, as settling it decides: a credit
     * note is issued and never paid, and a draft or a withdrawn one carries no number. A payment above zero is the
     * table's own constraint.
     *
     * @return iterable<DataFinding>
     */
    private function settlement(): iterable
    {
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            SELECT i.company_id, COALESCE(i.number, 'a draft') AS number, i.status
            FROM invoice i
            WHERE CASE
                WHEN i.status IN ('draft', 'cancelled') THEN i.number IS NOT NULL
                WHEN i.number IS NULL THEN true
                WHEN i.document_type = 'credit_note' THEN i.status <> 'issued' OR i.amount_paid <> 0
                WHEN i.amount_due IS NULL OR i.amount_due < 0 THEN true
                WHEN i.status = 'issued' THEN i.amount_paid <> 0 OR i.amount_credited <> 0
                WHEN i.status = 'paid' THEN i.amount_due <> 0
                WHEN i.status = 'partially_paid' THEN i.amount_due = 0 OR (i.amount_paid = 0 AND i.amount_credited = 0)
                ELSE true
            END
            ORDER BY 1, 2
            SQL);
        foreach ($rows as $row) {
            yield new DataFinding(self::SETTLEMENT, self::uuid($row['company_id']), \sprintf('%s is %s, which its figures or payments do not say', self::text($row['number']), self::text($row['status'])));
        }
    }

    /**
     * A customer's credit is the sum of its entries, money in above zero and money out below, and never below zero; an
     * entry paying credit into an invoice is its payment's amount, which takes the entry with it when deleted.
     *
     * @return iterable<DataFinding>
     */
    private function creditBalance(): iterable
    {
        // The balance is the customer kept's, over its own entries and those of every customer merged into it, which
        // stay where they were written: read per customer here, then folded as the credit repository reads it.
        $kept = $this->mergedCustomers->keptOf();
        $balances = [];
        foreach ($this->connection->iterateAssociative(<<<'SQL'
            SELECT company_id::text AS company, customer_id::text AS customer, SUM(amount)::text AS balance
            FROM customer_credit_entry
            GROUP BY company_id, customer_id
            SQL) as $row) {
            $customer = Rows::text($row['customer']);
            $account = Rows::text($row['company']).' '.($kept[$customer] ?? $customer);
            $balances[$account] = ($balances[$account] ?? new Number('0'))->add(Rows::sum($row['balance']));
        }
        $found = [];
        foreach ($balances as $account => $balance) {
            if ($balance < new Number('0')) {
                [$company, $customer] = explode(' ', $account);
                $found[] = [$company, $customer, 'its balance is below zero'];
            }
        }

        $rows = $this->connection->iterateAssociative(<<<'SQL'
            SELECT e.company_id::text AS company, e.customer_id::text AS customer, COUNT(*) || ' ' || e.kind || ' entries of the wrong sign or apart from their payment' AS wrong
            FROM customer_credit_entry e
            LEFT JOIN payment p ON p.id = e.payment_id
            WHERE (e.kind IN ('overpayment', 'credited') AND e.amount <= 0)
               OR (e.kind IN ('applied', 'refunded') AND e.amount >= 0)
               OR (e.kind = 'applied' AND (p.id IS NULL OR p.amount <> -e.amount OR p.invoice_id IS DISTINCT FROM e.invoice_id))
            GROUP BY e.company_id, e.customer_id, e.kind
            SQL);
        foreach ($rows as $row) {
            $found[] = [Rows::text($row['company']), Rows::text($row['customer']), Rows::text($row['wrong'])];
        }
        sort($found);
        foreach ($found as [$company, $customer, $wrong]) {
            yield new DataFinding(self::CREDIT_BALANCE, Uuid::fromString($company), \sprintf('customer %s: %s', $customer, $wrong));
        }
    }

    private static function uuid(mixed $value): Uuid
    {
        return Uuid::fromString(Rows::text($value));
    }

    private static function text(mixed $value): string
    {
        return Rows::text($value);
    }
}
