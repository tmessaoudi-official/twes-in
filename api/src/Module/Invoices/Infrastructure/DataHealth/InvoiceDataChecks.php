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
     * Each series' numbers, in the order they were issued, follow one another, or start again at one where a month
     * begins: read from the documents, not from the series' reset as it stands today, which may have been revised
     * since (yearly to monthly) and would then see a gap at every month the earlier numbers ran through. The first
     * number may be any, where the company continued a sequence begun elsewhere. The sequence is the number's last
     * digits, which is where every format ends it; a series whose format prints something after it is not checked
     * here rather than reported every night for a gap it does not have.
     *
     * @return iterable<DataFinding>
     */
    private function numbering(): iterable
    {
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            WITH numbered AS (
                SELECT i.company_id, i.establishment_id, i.document_type, i.number, i.issue_date,
                       substring(i.number FROM '([0-9]+)$')::bigint AS sequence,
                       LAG(substring(i.number FROM '([0-9]+)$')::bigint) OVER issued AS previous,
                       LAG(i.issue_date) OVER issued AS previous_day,
                       ROW_NUMBER() OVER issued AS nth
                FROM invoice i
                JOIN numbering_series s ON s.establishment_id = i.establishment_id AND s.document_type = i.document_type AND s.is_default
                WHERE i.number IS NOT NULL AND s.format ~ '\{SEQ(:[0-9]+)?\}$'
                -- The order issuing gave them: by day, then by number within it, the shorter first where it is not padded.
                WINDOW issued AS (PARTITION BY i.company_id, i.establishment_id, i.document_type ORDER BY i.issue_date, LENGTH(i.number), i.number, i.id)
            )
            SELECT company_id, establishment_id, document_type, number, previous
            FROM numbered
            WHERE sequence IS NULL
               OR (nth > 1 AND previous IS NOT NULL AND sequence <> previous + 1
                   AND NOT (sequence = 1 AND date_trunc('month', issue_date) <> date_trunc('month', previous_day)))
            ORDER BY company_id, establishment_id, document_type, issue_date, number
            SQL);
        foreach ($rows as $row) {
            yield new DataFinding(self::NUMBERING, self::uuid($row['company_id']), \sprintf(
                '%s %s of establishment %s does not follow %s',
                self::text($row['document_type']),
                self::text($row['number']),
                self::text($row['establishment_id']),
                null === $row['previous'] ? 'anything: its sequence cannot be read' : self::text($row['previous']),
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
