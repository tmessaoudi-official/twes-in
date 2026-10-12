<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Module\Invoices\Application\Chain\VerifyInvoiceChains;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * What the issued invoices and credit notes must still say: their chains hold to their recorded ends, their figures add
 * up as issuing computed them, and their numbers run without a gap in each series and period.
 */
final readonly class InvoiceDataChecks implements DeclaresDataChecks
{
    public const string CHAIN = 'invoices.chain';
    public const string ARITHMETIC = 'invoices.arithmetic';
    public const string NUMBERING = 'invoices.numbering';

    public function __construct(private VerifyInvoiceChains $chains, private Connection $connection)
    {
    }

    public function dataChecks(): array
    {
        return [self::CHAIN, self::ARITHMETIC, self::NUMBERING];
    }

    public function findings(string $check): iterable
    {
        return match ($check) {
            self::CHAIN => $this->chain(),
            self::ARITHMETIC => $this->arithmetic(),
            self::NUMBERING => $this->numbering(),
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
                    CASE WHEN i.amount_due IS DISTINCT FROM i.total_gross - COALESCE(i.withholding_amount, 0) - i.amount_paid - i.amount_credited THEN 'due' END,
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
     * Each series' numbers, per period it starts again in, run from one to the last without a gap or a repeat; the
     * first period may start higher, where the company continued a sequence begun elsewhere. The sequence is the
     * number's last digits, which is where every format ends it; a series whose format prints something after it is
     * not checked here rather than reported every night for a gap it does not have.
     *
     * @return iterable<DataFinding>
     */
    private function numbering(): iterable
    {
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            WITH numbered AS (
                SELECT i.company_id, i.establishment_id, i.document_type,
                       CASE s.reset_period WHEN 'yearly' THEN to_char(i.issue_date, 'YYYY') WHEN 'monthly' THEN to_char(i.issue_date, 'YYYY-MM') ELSE '' END AS period,
                       substring(i.number FROM '([0-9]+)$')::bigint AS sequence
                FROM invoice i
                JOIN numbering_series s ON s.establishment_id = i.establishment_id AND s.document_type = i.document_type AND s.is_default
                WHERE i.number IS NOT NULL AND s.format ~ '\{SEQ(:[0-9]+)?\}$'
            ), periods AS (
                SELECT company_id, establishment_id, document_type, period,
                       COUNT(*) FILTER (WHERE sequence IS NULL) AS unreadable,
                       COUNT(sequence) AS numbered, COUNT(DISTINCT sequence) AS different, MIN(sequence) AS first, MAX(sequence) AS last,
                       ROW_NUMBER() OVER (PARTITION BY company_id, establishment_id, document_type ORDER BY period) AS nth
                FROM numbered
                GROUP BY company_id, establishment_id, document_type, period
            )
            SELECT company_id, establishment_id, document_type, period, unreadable, numbered, different, first, last, nth
            FROM periods
            WHERE unreadable > 0 OR different <> numbered OR last - first + 1 <> numbered OR (nth > 1 AND first <> 1)
            ORDER BY company_id, establishment_id, document_type, period
            SQL);
        foreach ($rows as $row) {
            yield new DataFinding(self::NUMBERING, self::uuid($row['company_id']), \sprintf(
                '%s of establishment %s, period %s: %s numbers from %s to %s, %s different, %s unreadable',
                self::text($row['document_type']),
                self::text($row['establishment_id']),
                '' === $row['period'] ? '-' : self::text($row['period']),
                self::text($row['numbered']),
                self::text($row['first'] ?? '-'),
                self::text($row['last'] ?? '-'),
                self::text($row['different']),
                self::text($row['unreadable']),
            ));
        }
    }

    private static function uuid(mixed $value): Uuid
    {
        return Uuid::fromString(self::text($value));
    }

    private static function text(mixed $value): string
    {
        if (!\is_scalar($value)) {
            throw new \UnexpectedValueException('A data check read something other than a value.');
        }

        return (string) $value;
    }
}
