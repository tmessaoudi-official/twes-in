<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ImportExport\Application;

use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportContext;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportReport;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\ImportSwitch;
use App\ImportExport\Application\RowIdentity;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowNotes;
use App\ImportExport\Application\RowRejected;
use App\ImportExport\Application\RunImport;
use App\ImportExport\Application\UnreadableImport;
use App\ImportExport\Domain\ImportRun;
use App\ImportExport\Domain\ImportRunRepository;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The engine every import goes through, against a subject that only records what it was given. */
final class RunImportTest extends TestCase
{
    private FakeTransactions $transactions;
    private RecordingSubject $declaration;
    private Company $company;
    private InMemoryImportRuns $runs;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->transactions = new FakeTransactions();
        $this->declaration = new RecordingSubject();
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->runs = new InMemoryImportRuns();
        $this->clock = new MockClock('2026-10-09 10:00:00');
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

    public function testACommittedImportIsKeptUnderItsContentAndTheSameFileIsToldItWasImported(): void
    {
        $rows = [1 => ['number', 'name'], 2 => ['A-1', 'Alpha'], 3 => ['A-2', 'Beta']];
        $first = $this->importRows($rows, hash: self::hashOf('one'));
        self::assertNull($first->alreadyImportedAt, 'never imported before');
        self::assertCount(1, $this->runs->saved);
        $run = $this->runs->saved[0];
        self::assertSame(['things', self::hashOf('one'), 'create', 2, 0], [$run->getSubject(), $run->getContentHash(), $run->getMode(), $run->getCreated(), $run->getUpdated()]);
        self::assertSame($run->getId(), $this->declaration->context?->runId, 'what a row writes can carry the run it is kept under');

        $this->clock->sleep(3600);
        $preview = $this->importRows($rows, dryRun: true, hash: self::hashOf('one'));
        self::assertEquals(new \DateTimeImmutable('2026-10-09 10:00:00'), $preview->alreadyImportedAt);

        $other = $this->importRows($rows, dryRun: true, hash: self::hashOf('two'));
        self::assertNull($other->alreadyImportedAt, 'another file, though its rows are the same');
    }

    public function testAPreviewAndARefusedFileKeepNoRunAndSayNothingOnce(): void
    {
        $this->importRows([1 => ['number', 'name'], 2 => ['A-1', 'Alpha']], dryRun: true);
        $this->declaration->refuse['A-2'] = new RowRejected('name', 'Too short.', 'too_short');
        $this->importRows([1 => ['number', 'name'], 2 => ['A-2', 'B']]);

        self::assertSame([], $this->runs->saved);
        self::assertSame(0, $this->declaration->finished, 'what is said once for a file waits for a file that is stored');

        $this->importRows([1 => ['number', 'name'], 2 => ['A-1', 'Alpha']]);
        self::assertSame(1, $this->declaration->finished);
    }

    public function testOnlyTheSwitchesTheSubjectOffersReachIt(): void
    {
        $this->importRows([1 => ['number', 'name'], 2 => ['A-1', 'Alpha']], ticked: ['recount', 'delete_everything']);

        self::assertTrue($this->declaration->context?->ticked('recount'));
        self::assertFalse($this->declaration->context->ticked('delete_everything'));
    }

    public function testTheSubjectSaysWhatARowNamesSoARowFoundByAnotherColumnIsStillCaughtTwice(): void
    {
        $report = $this->importRows([1 => ['number', 'name', 'email'], 2 => ['', 'Alpha', 'a@x.tn'], 3 => ['', 'Alpha again', 'A@X.TN']]);

        self::assertSame([2], $report->created);
        self::assertSame([['line' => 3, 'column' => 'email', 'code' => 'duplicate_in_file', 'params' => ['line' => 2], 'message' => 'Line 2 of the file already has this email.']], $report->rejected);
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
        yield 'a required column missing' => [[1 => ['number', 'email'], 2 => ['A-1', '']], UnreadableImport::MISSING_COLUMNS, ['name']];
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

    /**
     * @param array<int, list<string>> $rows
     * @param list<string>             $ticked
     */
    private function importRows(array $rows, ImportMode $mode = ImportMode::Create, bool $dryRun = false, ?string $hash = null, array $ticked = []): ImportReport
    {
        $subject = $this->declaration->subjectFor($this->company);

        return (new RunImport($this->transactions, 3, $this->runs, $this->clock))->run($this->declaration, $subject, $this->company, $rows, $mode, $dryRun, Uuid::v7(), $hash ?? self::hashOf('file'), $ticked);
    }

    private static function hashOf(string $contents): string
    {
        return hash('sha256', $contents);
    }
}

/** The runs kept, in the order they were; the latest of one file is the one asked for. */
final class InMemoryImportRuns implements ImportRunRepository
{
    /** @var list<ImportRun> */
    public array $saved = [];

    public function lastOf(Uuid $companyId, string $subject, string $contentHash): ?ImportRun
    {
        $found = null;
        foreach ($this->saved as $run) {
            if ($run->getCompany()->getId()->equals($companyId) && $run->getSubject() === $subject && $run->getContentHash() === $contentHash) {
                $found = $run;
            }
        }

        return $found;
    }

    public function save(ImportRun $run): void
    {
        $this->saved[] = $run;
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
    public ?ImportContext $context = null;
    public int $finished = 0;

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

    /** By its number, else by its email whatever its case, as a subject finding a row by one column or another. */
    public function identityOf(Company $company, ImportRecord $record): ?RowIdentity
    {
        $email = $record->value('email');

        return RowIdentity::ofColumns($record, ['number']) ?? (null === $email ? null : new RowIdentity('email', 'email:'.mb_strtolower($email), 'email'));
    }

    public function subjectFor(Company $company): ImportSubject
    {
        return new ImportSubject('things', [new ImportColumn('number', 'n'), new ImportColumn('name', 'n', true), new ImportColumn('email', 'e')], [new ImportSwitch('recount', 'r')]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId, RowNotes $notes, ImportContext $context): RowImported
    {
        $this->seen[$record->line] = $record->values;
        $this->records[$record->line] = $record;
        $this->mode = $mode;
        $this->context = $context;
        $refusal = $this->refuse[(string) $record->value('number')] ?? null;
        if (null !== $refusal) {
            throw $refusal;
        }

        return RowImported::Created;
    }

    public function finished(Company $company, ImportContext $context, ?Uuid $actorUserId): void
    {
        ++$this->finished;
    }
}
