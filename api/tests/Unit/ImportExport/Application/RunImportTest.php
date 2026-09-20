<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ImportExport\Application;

use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportReport;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowRejected;
use App\ImportExport\Application\RunImport;
use App\ImportExport\Application\UnreadableImport;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** The engine every import goes through, against a subject that only records what it was given. */
final class RunImportTest extends TestCase
{
    private FakeTransactions $transactions;
    private RecordingSubject $declaration;
    private Company $company;

    protected function setUp(): void
    {
        $this->transactions = new FakeTransactions();
        $this->declaration = new RecordingSubject();
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    public function testRowsAreReadUnderTheirHeaderKeysAndCommittedOnce(): void
    {
        $report = $this->importRows([1 => ['number', 'name', 'email'], 2 => [' A-1 ', 'Alpha', ''], 3 => ['A-2', 'Beta', 'b@x.tn']]);

        self::assertTrue($report->committed);
        self::assertSame([2, 3], $report->created);
        self::assertSame(1, $this->transactions->committed);
        self::assertSame([2 => ['number' => 'A-1', 'name' => 'Alpha', 'email' => ''], 3 => ['number' => 'A-2', 'name' => 'Beta', 'email' => 'b@x.tn']], $this->declaration->seen);
        self::assertNull($this->declaration->records[2]->value('email'), 'a blank cell reads as absent');
    }

    public function testAHeaderIsMatchedWhateverItsCaseAndSurroundingSpaces(): void
    {
        $report = $this->importRows([1 => [' Number ', 'NAME'], 2 => ['A-1', 'Alpha']]);

        self::assertSame([2], $report->created);
    }

    public function testEmptyRowsAreSkippedAndLinesKeepTheFilesOwnNumbers(): void
    {
        $report = $this->importRows([1 => ['', ''], 2 => ['number', 'name'], 3 => ['', ''], 4 => ['A-1', 'Alpha'], 5 => [' ', '']]);

        self::assertSame([4], $report->created);
    }

    public function testAPreviewRunsEveryRowAndCommitsNothing(): void
    {
        $report = $this->importRows([1 => ['number', 'name'], 2 => ['A-1', 'Alpha'], 3 => ['A-2', 'Beta']], dryRun: true);

        self::assertFalse($report->committed);
        self::assertSame([2, 3], $report->created);
        self::assertSame(0, $this->transactions->committed, 'the unit of work was abandoned');
    }

    public function testOneRejectedRowCommitsNothingAndEveryOtherRowIsStillChecked(): void
    {
        $this->declaration->refuse['A-2'] = new RowRejected('name', 'Too short.', 'too_short', ['min' => 2]);

        $report = $this->importRows([1 => ['number', 'name'], 2 => ['A-1', 'Alpha'], 3 => ['A-2', 'B'], 4 => ['A-3', 'Gamma']]);

        self::assertFalse($report->committed);
        self::assertSame([2, 4], $report->created);
        self::assertSame([['line' => 3, 'column' => 'name', 'code' => 'too_short', 'params' => ['min' => 2], 'message' => 'Too short.']], $report->rejected);
        self::assertSame(0, $this->transactions->committed);
    }

    public function testASecondRowWithTheSameIdentityIsRejectedNamingTheFirst(): void
    {
        $report = $this->importRows([1 => ['number', 'name'], 2 => ['A-1', 'Alpha'], 3 => ['A-1', 'Alpha again']]);

        self::assertSame([2], $report->created);
        self::assertSame([['line' => 3, 'column' => 'number', 'code' => 'duplicate_in_file', 'params' => ['line' => 2], 'message' => 'Line 2 of the file already has this number.']], $report->rejected);
        self::assertSame([2], array_keys($this->declaration->seen), 'the duplicate never reaches the subject');
    }

    public function testTheModeReachesTheSubject(): void
    {
        $this->importRows([1 => ['number', 'name'], 2 => ['A-1', 'Alpha']], ImportMode::Upsert);

        self::assertSame(ImportMode::Upsert, $this->declaration->mode);
    }

    /** @return iterable<string, array{array<int, list<string>>, string, list<string>}> */
    public static function unreadable(): iterable
    {
        yield 'nothing at all' => [[], UnreadableImport::EMPTY, []];
        yield 'a header and no row' => [[1 => ['number', 'name'], 2 => ['', '']], UnreadableImport::EMPTY, []];
        yield 'a column nobody declares' => [[1 => ['number', 'name', 'colour', 'size'], 2 => ['A-1', 'Alpha', 'red', 'L']], UnreadableImport::UNKNOWN_COLUMNS, ['colour', 'size']];
        yield 'a required column missing' => [[1 => ['name', 'email'], 2 => ['Alpha', '']], UnreadableImport::MISSING_COLUMNS, ['number']];
        yield 'a column twice' => [[1 => ['number', 'name', 'Name'], 2 => ['A-1', 'Alpha', 'Beta']], UnreadableImport::DUPLICATE_COLUMNS, ['name']];
        yield 'more rows than allowed' => [[1 => ['number', 'name'], 2 => ['A-1', 'a'], 3 => ['A-2', 'b'], 4 => ['A-3', 'c'], 5 => ['A-4', 'd']], UnreadableImport::TOO_MANY_ROWS, []];
    }

    /**
     * @param array<int, list<string>> $rows
     * @param list<string>             $columns
     */
    #[DataProvider('unreadable')]
    public function testAFileTheEngineCannotReadIsRefusedWholeNamingWhy(array $rows, string $reason, array $columns): void
    {
        try {
            $this->importRows($rows);
            self::fail('The file was read.');
        } catch (UnreadableImport $refused) {
            self::assertSame([$reason, $columns], [$refused->reason, $refused->columns]);
        }
        self::assertSame([], $this->declaration->seen, 'no row reached the subject');
    }

    public function testABlankHeaderCellIsAnIgnoredColumn(): void
    {
        $report = $this->importRows([1 => ['number', '', 'name', ''], 2 => ['A-1', 'stray', 'Alpha', '']]);

        self::assertSame([2], $report->created);
        self::assertSame(['number' => 'A-1', 'name' => 'Alpha'], $this->declaration->seen[2]);
    }

    /** @param array<int, list<string>> $rows */
    private function importRows(array $rows, ImportMode $mode = ImportMode::Create, bool $dryRun = false): ImportReport
    {
        $subject = $this->declaration->subjectFor($this->company);

        return (new RunImport($this->transactions, 3))->run($this->declaration, $subject, $this->company, $rows, $mode, $dryRun, Uuid::v7());
    }
}

/** A subject with a required number and name and an optional email, which records every row it is given. */
final class RecordingSubject implements DeclaresImport
{
    /** @var array<int, array<string, string>> */
    public array $seen = [];
    /** @var array<int, ImportRecord> */
    public array $records = [];
    /** @var array<string, RowRejected> refusals by number */
    public array $refuse = [];
    public ?ImportMode $mode = null;

    public function key(): string
    {
        return 'things';
    }

    public function permission(): string
    {
        return 'thing.write';
    }

    public function module(): string
    {
        return 'things';
    }

    public function identityColumns(): array
    {
        return ['number'];
    }

    public function subjectFor(Company $company): ImportSubject
    {
        return new ImportSubject('things', [new ImportColumn('number', 'n', true), new ImportColumn('name', 'n', true), new ImportColumn('email', 'e')]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId): RowImported
    {
        $this->seen[$record->line] = $record->values;
        $this->records[$record->line] = $record;
        $this->mode = $mode;
        $refusal = $this->refuse[(string) $record->value('number')] ?? null;
        if (null !== $refusal) {
            throw $refusal;
        }

        return RowImported::Created;
    }
}
