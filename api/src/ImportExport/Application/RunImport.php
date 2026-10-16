<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\Identity\Application\StepUp\StepUpRequired;
use App\ImportExport\Domain\Model\ImportRun;
use App\ImportExport\Domain\Repository\ImportRunRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Imports a file's rows into one subject, all or nothing (docs/SPEC.md § 7, 2026-09-17 and 2026-09-19).
 *
 * The file's first non-empty row is its header, read on the subject's column keys and nothing else: a header cell that
 * is not one of them refuses the file, because an importer that guesses where a column goes puts data in the wrong
 * field. Every row then goes through the subject, which uses the same use case a person's form does, inside ONE unit
 * of work. A preview and a file with any rejected row both roll that unit back, so the preview is exactly what the
 * import would do, rejections included, and an import is never half applied.
 *
 * A committed import is kept as an ImportRun under the SHA-256 of its file, so the next run of the very same file is
 * told when it was already imported, before anything is confirmed.
 */
final readonly class RunImport
{
    public function __construct(
        private Transactions $transactions,
        #[Autowire(param: 'app.import.max_rows')]
        private int $maxRows,
        private ImportRunRepository $runs,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param iterable<int, list<string>> $rows        the file's rows by their own line numbers
     * @param string                      $contentHash the SHA-256 of the file's bytes, lowercase hexadecimal
     * @param list<string>                $ticked      the switches the request ticks; those the subject does not offer are ignored
     *
     * @throws UnreadableImport
     * @throws StepUpRequired   when a row changes what asks who is at the screen again, without a recent proof: the
     *                          whole file is rolled back, and kept once the proof is given
     */
    public function run(DeclaresImport $declaration, ImportSubject $subject, Company $company, iterable $rows, ImportMode $mode, bool $dryRun, ?Uuid $actorUserId, string $contentHash, array $ticked = []): ImportReport
    {
        $records = $this->records($subject, $rows);
        $already = $this->runs->lastOf($company->getId(), $declaration->key(), $contentHash)?->getAt();
        $context = new ImportContext(Uuid::v7(), $subject->ticked($ticked), $dryRun, $subject->keys());

        try {
            return $this->transactions->run(function () use ($declaration, $subject, $company, $records, $mode, $dryRun, $actorUserId, $context, $contentHash, $already): ImportReport {
                $report = $this->apply($declaration, $subject, $company, $records, $mode, $actorUserId, $context);
                if ($dryRun || [] !== $report->rejected) {
                    throw new ImportRolledBack(new ImportReport(false, $report->created, $report->updated, $report->rejected, $report->notes, $already, $report->changes, $report->unchanged));
                }
                $declaration->finished($company, $context, $actorUserId);
                $this->runs->save(new ImportRun($context->runId, $company, $declaration->key(), $contentHash, $mode->value, $actorUserId, \count($report->created), \count($report->updated), $this->clock->now()));

                return new ImportReport(true, $report->created, $report->updated, [], $report->notes, $already, $report->changes, $report->unchanged);
            });
        } catch (ImportRolledBack $rolledBack) {
            return $rolledBack->report;
        }
    }

    /**
     * @param list<ImportRecord> $records
     */
    private function apply(DeclaresImport $declaration, ImportSubject $subject, Company $company, array $records, ImportMode $mode, ?Uuid $actorUserId, ImportContext $context): ImportReport
    {
        // A row's faults are said in the order of its columns, as a person reads the row; a fault of no column last.
        $position = array_flip($subject->keys());
        $created = $updated = $unchanged = $rejected = $noted = $changed = [];
        /** @var array<string, int> $firstLineOf the first line naming each identity */
        $firstLineOf = [];
        foreach ($records as $record) {
            if ($record->unreadable) {
                // Its bytes are no text: named by its line alone, none of them echoed.
                $rejected[] = ['line' => $record->line, 'column' => null, 'code' => 'invalid_encoding', 'params' => [], 'message' => 'The row holds bytes the encoding the file is read in cannot read.'];
                continue;
            }
            if (null !== $record->overflow) {
                // Read without the value it has no column for, the row would store less than it says.
                $rejected[] = ['line' => $record->line, 'column' => null, 'code' => 'too_many_cells', 'params' => $record->overflow, 'message' => \sprintf('The row has values in %d columns; the header has %d.', $record->overflow['count'], $record->overflow['expected'])];
                continue;
            }
            $identity = $declaration->identityOf($company, $record);
            if (null !== $identity && isset($firstLineOf[$identity->key])) {
                $rejected[] = ['line' => $record->line, 'column' => $identity->column, 'code' => 'duplicate_in_file', 'params' => ['line' => $firstLineOf[$identity->key]], 'message' => \sprintf('Line %d of the file already has this %s.', $firstLineOf[$identity->key], $identity->named)];
                continue;
            }
            if (null !== $identity) {
                $firstLineOf[$identity->key] = $record->line;
            }

            $notes = new RowNotes();
            try {
                match ($declaration->import($company, $record, $mode, $actorUserId, $notes, $context)) {
                    RowImported::Created => $created[] = $record->line,
                    RowImported::Updated => $updated[] = $record->line,
                    RowImported::Unchanged => $unchanged[] = $record->line,
                };
                foreach ($notes->all() as $note) {
                    $noted[] = ['line' => $record->line, ...$note];
                }
                // In the order of the columns, as the subject compares them.
                foreach ($notes->changes() as $change) {
                    $changed[] = ['line' => $record->line, ...$change];
                }
            } catch (RowRejected $refused) {
                $faults = $refused->all();
                usort($faults, static fn (RowRejected $one, RowRejected $other): int => ($position[$one->column ?? ''] ?? \PHP_INT_MAX) <=> ($position[$other->column ?? ''] ?? \PHP_INT_MAX));
                foreach ($faults as $fault) {
                    $rejected[] = ['line' => $record->line, 'column' => $fault->column, 'code' => $fault->reason, 'params' => $fault->params, 'message' => $fault->getMessage()];
                }
            }
        }

        return new ImportReport(false, $created, $updated, $rejected, $noted, null, $changed, $unchanged);
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
        $width = 0;
        $records = [];
        $blank = 0;
        foreach ($rows as $line => $cells) {
            $cells = array_map(trim(...), $cells);
            if ('' === implode('', $cells)) {
                // Skipped, not free: a file of nothing but blank lines, or a workbook whose one cell sits on its millionth
                // row, would otherwise be read to the end.
                if (++$blank > $this->maxRows) {
                    throw new UnreadableImport(UnreadableImport::TOO_MANY_ROWS, [], $this->maxRows);
                }
                continue;
            }
            $readable = mb_check_encoding(implode('', $cells), 'UTF-8');
            if (null === $header) {
                if (!$readable) {
                    throw new UnreadableImport(UnreadableImport::UNREADABLE_ENCODING);
                }
                $header = $this->header($subject, $cells);
                $width = \count($cells);
                continue;
            }
            if (\count($records) === $this->maxRows) {
                throw new UnreadableImport(UnreadableImport::TOO_MANY_ROWS, [], $this->maxRows);
            }
            if (!$readable) {
                $records[] = new ImportRecord($line, [], unreadable: true);
                continue;
            }
            $values = [];
            foreach ($header as $position => $key) {
                $values[$key] = $cells[$position] ?? '';
            }
            // A blank header cell is a column ignored, so only a value past the header row itself has nowhere to go.
            $last = array_key_last(array_filter($cells, static fn (string $cell): bool => '' !== $cell)) ?? 0;
            $records[] = new ImportRecord($line, $values, $last >= $width ? ['count' => $last + 1, 'expected' => $width] : null);
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
