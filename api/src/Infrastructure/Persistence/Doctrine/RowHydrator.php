<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine;

use Symfony\Component\Uid\Uuid;
use Twes\Infrastructure\Persistence\Doctrine\Entity\DocumentChargeRow;
use Twes\Infrastructure\Persistence\Doctrine\Entity\DocumentLineRow;
use Twes\Infrastructure\Persistence\Doctrine\Entity\DocumentRow;

/**
 * A DBAL associative row → the typed row object {@see InvoiceMapper} consumes.
 *
 * **This exists because the repository writes and reads with DBAL rather than through the UnitOfWork**
 * ({@see DoctrineInvoiceRepository} states why, with the measurement), so nothing else turns a `SELECT` result into
 * the mapped entities. Doctrine's own hydration would do it, and it comes with the identity map that makes the
 * whole-rewrite impossible — so this is the cost of that decision, kept in one place and small enough to read.
 *
 * **A SEPARATE CLASS RATHER THAN THREE PRIVATE METHODS ON THE REPOSITORY.** The repository has one job and this is
 * a different one, but the real reason is testability: these three functions are where a column name typo becomes a
 * silently wrong value, and `array_map(RowHydrator::line(...), $rows)` at the call site is only safe if the mapping
 * from column name to constructor position is asserted directly. A private method could only be tested through a
 * database round trip, which is exactly the test that would pass while both ends agreed on the wrong thing.
 *
 * **EVERY COLUMN IS READ BY NAME AND CAST EXPLICITLY**, and both halves matter. pdo_pgsql returns `bigint` as a
 * native `int` and `numeric` as a `string` — the second is what the domain requires, because a `numeric` narrowed to
 * a float would put a float next to money, which `CLAUDE.md` § Gotchas calls unfixable later. So the casts here are
 * not defensive noise: `(string)` on a decimal column is the type the domain demands, and `(int)` on `position` and
 * `number` is what the row objects declare.
 */
final readonly class RowHydrator
{
    /**
     * @param array<string, mixed> $row
     */
    public static function document(array $row): DocumentRow
    {
        $document = new DocumentRow(
            Uuid::fromString(self::text($row, 'company_id')),
            Uuid::fromString(self::text($row, 'id')),
            self::text($row, 'type'),
            self::text($row, 'state'),
            self::text($row, 'currency'),
            self::text($row, 'vat_rounding_point'),
        );

        // THE NULLABLE PAIR, assigned together for the reason `InvoiceMapper::toRows()` gives: they are one
        // decision, and the mapper REFUSES a row carrying one without the other. `null === $x ? null : (int) $x`
        // rather than a cast on a possibly-null value, because `(int) null` is 0 and 0 is a sequence the domain
        // refuses — a silent draft-to-invoice-zero conversion is exactly the shape a cast hides.
        $number = $row['number'] ?? null;
        $document->number = null === $number ? null : (int) $number;

        $rendered = $row['number_rendered'] ?? null;
        $document->numberRendered = null === $rendered ? null : (string) $rendered;

        // THE CLIENT, hydrated the same way and for the same reason: it is a nullable column set by assignment
        // rather than through the constructor, so a row that omitted it would leave the property at its default
        // and read as a draft with no client — the silent substitution `RowEntityInstantiationTest` refuses.
        $client = $row['client_id'] ?? null;
        $document->clientId = null === $client ? null : Uuid::fromString((string) $client);

        return $document;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function line(array $row): DocumentLineRow
    {
        return new DocumentLineRow(
            Uuid::fromString(self::text($row, 'company_id')),
            Uuid::fromString(self::text($row, 'document_id')),
            (int) self::text($row, 'position'),
            self::text($row, 'quantity'),
            self::text($row, 'unit_net'),
            self::text($row, 'vat_rate'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function charge(array $row): DocumentChargeRow
    {
        return new DocumentChargeRow(
            Uuid::fromString(self::text($row, 'company_id')),
            Uuid::fromString(self::text($row, 'document_id')),
            (int) self::text($row, 'position'),
            self::text($row, 'label'),
            self::text($row, 'amount'),
        );
    }

    /**
     * One NOT NULL column as a string, refusing an absent or null value rather than coercing it.
     *
     * **`(string) null` is `''`, and `''` is what makes this worth eleven lines.** An empty string reaches
     * `Uuid::fromString()` as an exception (fine), but it reaches `Money::of()` and `Currency::of()` as a value —
     * and a missing column name would then surface as an invalid-currency error pointing at the domain rather than
     * at the `SELECT` that forgot to list a column. Naming the column in the failure is what makes a typo a
     * one-line fix instead of an investigation.
     *
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): string
    {
        if (!isset($row[$column])) {
            throw new \LogicException(\sprintf(
                'Row is missing the NOT NULL column "%s". Columns present: %s. This is our own SELECT failing to '
                . 'list a column, not a data problem — a coerced empty string here would surface much later as a '
                . 'domain validation error pointing away from the cause.',
                $column,
                '' === implode(', ', array_keys($row)) ? '<none>' : implode(', ', array_keys($row)),
            ));
        }

        return (string) $row[$column];
    }
}
