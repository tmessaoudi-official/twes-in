<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Fuzz;

use App\ImportExport\Application\ImportSettings;
use App\Shared\Application\Spreadsheet\SpreadsheetReader;
use App\Shared\Application\Spreadsheet\UnreadableSpreadsheet;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The spreadsheet port over every file of the corpus: a file is read to its end or refused as unreadable, never left
 * to escape as another exception, which the import would answer as a 500; and a declared document type is always
 * refused as one, before any XML parser reads it.
 */
final class SpreadsheetReaderFuzzTest extends KernelTestCase
{
    private const array HEADER = ['number', 'name', 'kind'];

    /** Above the import's own ceiling of rows, `ImportSettings::CEILING`, so whatever the import would read is read. */
    private const int ROWS_READ = ImportSettings::CEILING * 2;

    /** No file of the corpus takes long to read; one that did would be a way to hold a worker. */
    private const int SECONDS_A_FILE = 15;

    public function testEveryFileOfTheCorpusIsReadOrRefusedAsUnreadable(): void
    {
        $files = [...ImportFuzzCorpus::rowFiles(self::HEADER), ...ImportFuzzCorpus::fileFiles()];
        foreach (ImportFuzzCorpus::seeds() as $seed) {
            $files = [...$files, ...ImportFuzzCorpus::seededFiles($seed)];
        }

        $reader = static::getContainer()->get(SpreadsheetReader::class);
        $doctypes = 0;
        // Every file is read before the test says what went wrong, so one red names all the files that are.
        $problems = [];
        foreach ($files as $file) {
            $format = $file->format();
            if (null === $format) {
                // A format the import does not read is refused by its name, before any reader sees it.
                continue;
            }
            $path = tempnam(sys_get_temp_dir(), 'fuzz-');
            self::assertIsString($path);
            file_put_contents($path, $file->bytes);
            $refused = null;
            $started = hrtime(true);
            try {
                $read = 0;
                foreach ($reader->rows($path, $format, $reader->encodingOf($path, $format)) as $cells) {
                    // The import stops a file at its row limit; a row numbered in the billions is a run of empty rows
                    // the reader hands over one by one, which only that limit ends.
                    if (++$read > self::ROWS_READ) {
                        break;
                    }
                }
            } catch (UnreadableSpreadsheet $unreadable) {
                $refused = $unreadable;
            } catch (\Throwable $escaped) {
                $problems[] = \sprintf('%s: %s escaped the reader: %s', $file->name, $escaped::class, $escaped->getMessage());
            } finally {
                unlink($path);
            }
            if ((hrtime(true) - $started) / 1e9 > self::SECONDS_A_FILE) {
                $problems[] = $file->name.': took longer than '.self::SECONDS_A_FILE.' s to read';
            }

            if ($file->declaresDoctype) {
                ++$doctypes;
                if (UnreadableSpreadsheet::DOCUMENT_TYPE !== $refused?->reason) {
                    $problems[] = $file->name.': a declared document type was not refused as one';
                }
            }
        }

        self::assertSame([], $problems);
        // The document type cases are the corpus's point: losing them would leave the test green on nothing.
        self::assertGreaterThanOrEqual(15, $doctypes);
    }
}
