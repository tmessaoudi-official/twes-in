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
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Imports a file's rows into one subject, all or nothing (docs/SPEC.md § 7, 2026-09-17 and 2026-09-19).
 *
 * The file's first non-empty row is its header, read on the subject's column keys, or on the pairing a person made of
 * the file's own headings with those columns (a heading paired with nothing is a column ignored). A header cell neither
 * names refuses the file, because an importer that guesses where a column goes puts data in the wrong field; a pairing
 * is a person's choice, kept with the committed import and offered again for the next file. Every row then goes through the subject, which uses the same use case a person's form does, inside ONE unit
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
        private ReadSetting $settings,
        private ImportRunRepository $runs,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param iterable<int, list<string>> $rows        the file's rows by their own line numbers
     * @param string                      $contentHash the SHA-256 of the file's bytes, lowercase hexadecimal
     * @param list<string>                $ticked      the switches the request ticks; those the subject does not offer are ignored
     * @param array<string, string>       $pairing     a file's own headings, as a person paired them, by the column key each is
     *                                                 read as, or '' for a column ignored; a heading the file lacks is left aside
     *
     * @throws UnreadableImport
     * @throws StepUpRequired   when a row changes what asks who is at the screen again, without a recent proof: the
     *                          whole file is rolled back, and kept once the proof is given
     */
    public function run(DeclaresImport $declaration, ImportSubject $subject, Company $company, iterable $rows, ImportMode $mode, bool $dryRun, ?Uuid $actorUserId, string $contentHash, array $ticked = [], array $pairing = []): ImportReport
    {
        [$records, $paired] = $this->records($subject, $rows, $pairing);
        $already = $this->runs->lastOf($company->getId(), $declaration->key(), $contentHash)?->getAt();
        $context = new ImportContext(Uuid::v7(), $subject->ticked($ticked), $dryRun, $subject->keys());

        try {
            return $this->transactions->run(function () use ($declaration, $subject, $company, $records, $mode, $dryRun, $actorUserId, $context, $contentHash, $already, $paired): ImportReport {
                $report = $this->apply($declaration, $subject, $company, $records, $mode, $actorUserId, $context);
                if ($dryRun || [] !== $report->rejected) {
                    throw new ImportRolledBack(new ImportReport(false, $report->created, $report->updated, $report->rejected, $report->notes, $already, $report->changes, $report->unchanged));
                }
                $declaration->finished($company, $context, $actorUserId);
                $this->runs->save(new ImportRun($context->runId, $company, $declaration->key(), $contentHash, $mode->value, $actorUserId, \count($report->created), \count($report->updated), $this->clock->now(), $paired));
                $this->runs->saveCreated($company->getId(), $context->runId, $context->createdRecords());

                return new ImportReport(true, $report->created, $report->updated, [], $report->notes, $already, $report->changes, $report->unchanged, $context->runId);
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
     * The header, matched on the subject's keys or the pairing whatever its case and surrounding spaces, then every
     * non-empty row.
     *
     * @param iterable<int, list<string>> $rows
     * @param array<string, string>       $pairing
     *
     * @return array{list<ImportRecord>, array<string, string>} the rows, and the pairing the header needed
     *
     * @throws UnreadableImport
     */
    private function records(ImportSubject $subject, iterable $rows, array $pairing): array
    {
        // The platform's, read for each file: an operator's change holds from the next one.
        $maxRows = $this->settings->integer(new SettingContext(), ImportSettings::MAX_ROWS);
        $paired = [];
        $header = null;
        $width = 0;
        $records = [];
        $blank = 0;
        foreach ($rows as $line => $cells) {
            $cells = array_map(trim(...), $cells);
            if ('' === implode('', $cells)) {
                // Skipped, not free: a file of nothing but blank lines, or a workbook whose one cell sits on its millionth
                // row, would otherwise be read to the end.
                if (++$blank > $maxRows) {
                    throw new UnreadableImport(UnreadableImport::TOO_MANY_ROWS, [], $maxRows);
                }
                continue;
            }
            $readable = mb_check_encoding(implode('', $cells), 'UTF-8');
            if (null === $header) {
                if (!$readable) {
                    throw new UnreadableImport(UnreadableImport::UNREADABLE_ENCODING);
                }
                [$header, $paired] = $this->header($subject, $cells, $pairing);
                $width = \count($cells);
                continue;
            }
            if (\count($records) === $maxRows) {
                throw new UnreadableImport(UnreadableImport::TOO_MANY_ROWS, [], $maxRows);
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

        return [$records, $paired];
    }

    /**
     * @param list<string>          $cells
     * @param array<string, string> $pairing
     *
     * @return array{array<int, string>, array<string, string>} the column key read at each position (a blank header
     *                                                          cell is a column ignored), and the pairing of the file's
     *                                                          headings it used, as the file writes them
     *
     * @throws UnreadableImport
     */
    private function header(ImportSubject $subject, array $cells, array $pairing): array
    {
        $known = [];
        foreach ($subject->keys() as $key) {
            $known[mb_strtolower($key)] = $key;
        }
        // A person's pairing comes first: it may set aside a heading that happens to be a column's own key.
        $choices = [];
        foreach ($pairing as $heading => $choice) {
            $choices[mb_strtolower(trim((string) $heading))] = trim($choice);
        }

        $header = $paired = $unknown = $invalid = $twice = [];
        foreach ($cells as $position => $cell) {
            if ('' === $cell) {
                continue;
            }
            $choice = $choices[mb_strtolower($cell)] ?? null;
            if ('' === $choice) {
                $paired[$cell] = '';
                continue;
            }
            $key = null === $choice ? $known[mb_strtolower($cell)] ?? null : $known[mb_strtolower($choice)] ?? null;
            // The heading is named, not the choice: it is the heading the person pairs again.
            if (null !== $choice && null === $key) {
                $invalid[] = $cell;
                continue;
            }
            if (null === $key) {
                $unknown[] = $cell;
                continue;
            }
            if (null !== $choice) {
                $paired[$cell] = $key;
            }
            if (\in_array($key, $header, true)) {
                $twice[] = $key;
                continue;
            }
            $header[$position] = $key;
        }

        if ([] !== $invalid) {
            throw new UnreadableImport(UnreadableImport::INVALID_PAIRING, array_values(array_unique($invalid)));
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

        return [$header, $paired];
    }
}
