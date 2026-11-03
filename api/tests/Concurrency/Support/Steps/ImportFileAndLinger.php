<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\ImportExport\Application\ImportCatalogue;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\RunImport;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\SpreadsheetReader;
use App\Shared\Application\Transactions;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RacerContext;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/**
 * Every racer imports the same CSV file, `csv`, into the subject `subject` in `mode`, read as the import's controller
 * reads an upload, inside a unit of work that stays open `lingerMs` after the import returns, as a request still writing
 * after it: what one run wrote reaches another only once it commits, every time, not when the timing falls that way.
 * It answers what its report said: committed, and when the file was already imported, if it was told.
 */
final class ImportFileAndLinger implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $company = RacerContext::company($container, $arguments);
        $subject = Rows::text($arguments['subject'] ?? null);
        $mode = ImportMode::from(Rows::text($arguments['mode'] ?? null));
        $actor = Uuid::fromString(Rows::text($arguments['actor'] ?? null));
        $linger = $arguments['lingerMs'] ?? null;
        RacerContext::signedIn($container, $actor);
        $file = (string) tempnam(sys_get_temp_dir(), 'race-import-');
        try {
            file_put_contents($file, Rows::text($arguments['csv'] ?? null));
            $catalogue = $container->get(ImportCatalogue::class);
            $hash = (string) hash_file('sha256', $file);
            $rows = $container->get(SpreadsheetReader::class)->rows($file, SpreadsheetFormat::Csv);

            return $container->get(Transactions::class)->run(static function () use ($container, $catalogue, $company, $subject, $mode, $actor, $hash, $rows, $linger): array {
                $report = $container->get(RunImport::class)->run($catalogue->declaration($subject), $catalogue->subject($subject, $company), $company, $rows, $mode, false, $actor, $hash);
                usleep(1000 * (\is_int($linger) ? $linger : 0));

                return ['committed' => $report->committed, 'alreadyImportedAt' => $report->alreadyImportedAt?->format(\DATE_ATOM), 'rejected' => $report->rejected];
            });
        } finally {
            @unlink($file);
        }
    }
}
