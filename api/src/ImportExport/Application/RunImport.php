<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Imports a file's rows into one subject, all or nothing (docs/SPEC.md § 7, 2026-09-17 and 2026-09-19).
 *
 * The file's first non-empty row is its header, read on the subject's column keys and nothing else: a header cell that
 * is not one of them refuses the file, because an importer that guesses where a column goes puts data in the wrong
 * field. Every row then goes through the subject, which uses the same use case a person's form does, inside ONE unit
 * of work. A preview and a file with any rejected row both roll that unit back, so the preview is exactly what the
 * import would do, rejections included, and an import is never half applied.
 */
final readonly class RunImport
{
    public function __construct(
        private Transactions $transactions,
        private int $maxRows,
    ) {
    }

    /**
     * @param iterable<int, list<string>> $rows the file's rows by their own line numbers
     *
     * @throws UnreadableImport
     */
    public function run(DeclaresImport $declaration, ImportSubject $subject, Company $company, iterable $rows, ImportMode $mode, bool $dryRun, ?Uuid $actorUserId): ImportReport
    {
        $records = $this->records($subject, $rows);

        try {
            return $this->transactions->run(function () use ($declaration, $company, $records, $mode, $dryRun, $actorUserId): ImportReport {
                $report = $this->apply($declaration, $company, $records, $mode, $actorUserId);
                if ($dryRun || [] !== $report->rejected) {
                    throw new ImportRolledBack($report);
                }

                return new ImportReport(true, $report->created, $report->updated, []);
            });
        } catch (ImportRolledBack $rolledBack) {
            return $rolledBack->report;
        }
    }

    /**
     * @param list<ImportRecord> $records
     */
    private function apply(DeclaresImport $declaration, Company $company, array $records, ImportMode $mode, ?Uuid $actorUserId): ImportReport
    {
        $identity = $declaration->identityColumns();
        $created = $updated = $rejected = [];
        /** @var array<string, int> $firstLineOf the first line naming each identity */
        $firstLineOf = [];
        foreach ($records as $record) {
            $key = self::identityOf($record, $identity);
            if (null !== $key && isset($firstLineOf[$key])) {
                $rejected[] = ['line' => $record->line, 'column' => $identity[0], 'code' => 'duplicate_in_file', 'params' => ['line' => $firstLineOf[$key]], 'message' => \sprintf('Line %d of the file already has this %s.', $firstLineOf[$key], implode(' and ', $identity))];
                continue;
            }
            if (null !== $key) {
                $firstLineOf[$key] = $record->line;
            }

            try {
                match ($declaration->import($company, $record, $mode, $actorUserId)) {
                    RowImported::Created => $created[] = $record->line,
                    RowImported::Updated => $updated[] = $record->line,
                };
            } catch (RowRejected $refused) {
                $rejected[] = ['line' => $record->line, 'column' => $refused->column, 'code' => $refused->reason, 'params' => $refused->params, 'message' => $refused->getMessage()];
            }
        }

        return new ImportReport(false, $created, $updated, $rejected);
    }

    /**
     * What this row names itself by, for finding the SAME thing twice in one file. A row that leaves any of the
     * identity's columns empty has no identity at all — it is rejected by the subject for the missing value, which
     * says more than "a duplicate of the other row that is also empty" would.
     *
     * The parts are joined on a separator no cell can hold, so a pair like ("VIS-6", "A-12") can never collide with
     * ("VIS", "6A-12").
     *
     * @param non-empty-list<string> $identity
     */
    private static function identityOf(ImportRecord $record, array $identity): ?string
    {
        $parts = [];
        foreach ($identity as $column) {
            $value = $record->value($column);
            if (null === $value) {
                return null;
            }
            $parts[] = $value;
        }

        return implode("\x1f", $parts);
    }

    /**
     * The header, matched on the subject's keys whatever its case and surrounding spaces, then every non-empty row.
     *
     * @param iterable<int, list<string>> $rows
     *
     * @return list<ImportRecord>
     *
     * @throws UnreadableImport
     */
    private function records(ImportSubject $subject, iterable $rows): array
    {
        $header = null;
        $records = [];
        foreach ($rows as $line => $cells) {
            $cells = array_map(trim(...), $cells);
            if ('' === implode('', $cells)) {
                continue;
            }
            if (null === $header) {
                $header = $this->header($subject, $cells);
                continue;
            }
            if (\count($records) === $this->maxRows) {
                throw new UnreadableImport(UnreadableImport::TOO_MANY_ROWS, [], $this->maxRows);
            }
            $values = [];
            foreach ($header as $position => $key) {
                $values[$key] = $cells[$position] ?? '';
            }
            $records[] = new ImportRecord($line, $values);
        }

        if ([] === $records) {
            throw new UnreadableImport(UnreadableImport::EMPTY);
        }

        return $records;
    }

    /**
     * @param list<string> $cells
     *
     * @return array<int, string> the column key read at each position; a blank header cell is a column ignored
     *
     * @throws UnreadableImport
     */
    private function header(ImportSubject $subject, array $cells): array
    {
        $known = [];
        foreach ($subject->keys() as $key) {
            $known[mb_strtolower($key)] = $key;
        }

        $header = $unknown = $twice = [];
        foreach ($cells as $position => $cell) {
            if ('' === $cell) {
                continue;
            }
            $key = $known[mb_strtolower($cell)] ?? null;
            if (null === $key) {
                $unknown[] = $cell;
                continue;
            }
            if (\in_array($key, $header, true)) {
                $twice[] = $key;
                continue;
            }
            $header[$position] = $key;
        }

        if ([] !== $unknown) {
            throw new UnreadableImport(UnreadableImport::UNKNOWN_COLUMNS, $unknown);
        }
        if ([] !== $twice) {
            throw new UnreadableImport(UnreadableImport::DUPLICATE_COLUMNS, array_values(array_unique($twice)));
        }
        $missing = [];
        foreach ($subject->columns as $column) {
            if ($column->required && !\in_array($column->key, $header, true)) {
                $missing[] = $column->key;
            }
        }
        if ([] !== $missing) {
            throw new UnreadableImport(UnreadableImport::MISSING_COLUMNS, $missing);
        }

        return $header;
    }
}
