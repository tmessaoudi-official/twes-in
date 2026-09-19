<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Http;

use App\ImportExport\Application\ImportCatalogue;
use App\ImportExport\Application\UnknownImportSubject;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\SpreadsheetWriter;
use App\Shared\Application\Spreadsheet\UnwritableSpreadsheet;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The empty file a person fills in, for one subject and one company (docs/SPEC.md § 7, 2026-09-17). A plain
 * controller rather than a resource: the answer is bytes, not JSON.
 *
 * It holds ONE row, the column keys. No example row: a template that ships with a specimen customer in it imports
 * that specimen the first time somebody forgets to delete the line. What each column means, and an example of it, is
 * shown on the screen that offers the download.
 */
#[AsController]
final readonly class ImportTemplateController
{
    public function __construct(
        private ImportCatalogue $catalogue,
        private SpreadsheetWriter $writer,
        private CompanyGuard $guard,
    ) {
    }

    #[Route('/api/companies/{companyId}/import-templates/{subject}.{format}', name: 'api_import_template', requirements: ['subject' => '[a-z-]+', 'format' => 'csv|xlsx'], methods: ['GET'])]
    public function __invoke(string $companyId, string $subject, string $format): Response
    {
        try {
            // Importing writes; offering the file to somebody who could not use it would be a dead end with a download in it.
            $permission = $this->catalogue->declaration($subject)->permission();
            $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), $permission);
            $columns = $this->catalogue->subject($subject, $company)->keys();
        } catch (UnknownImportSubject $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        }

        $spreadsheet = SpreadsheetFormat::from($format);
        // The writer's contract is a path, because a .xlsx is a zip archive and a zip has to be seekable.
        $path = tempnam(sys_get_temp_dir(), 'twes-template-');
        if (false === $path) {
            throw new ServiceUnavailableHttpException(30, 'The template could not be prepared; try again shortly.');
        }

        try {
            $this->writer->write($path, $spreadsheet, [$columns]);
            $contents = file_get_contents($path);
        } catch (UnwritableSpreadsheet $failure) {
            throw new ServiceUnavailableHttpException(30, 'The template could not be prepared; try again shortly.', $failure);
        } finally {
            @unlink($path);
        }

        if (false === $contents) {
            throw new ServiceUnavailableHttpException(30, 'The template could not be read back; try again shortly.');
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $spreadsheet->mediaType(),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('%s.%s', $subject, $spreadsheet->extension())),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
