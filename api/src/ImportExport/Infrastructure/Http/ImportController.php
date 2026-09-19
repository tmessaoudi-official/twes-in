<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Http;

use App\ImportExport\Application\ImportCatalogue;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\RunImport;
use App\ImportExport\Application\UnknownImportSubject;
use App\ImportExport\Application\UnreadableImport;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\SpreadsheetReader;
use App\Shared\Application\Spreadsheet\UnreadableSpreadsheet;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Previews or imports one file (docs/SPEC.md § 7, 2026-09-17 and 2026-09-19): `file` a .csv or .xlsx, `mode` create or
 * upsert, `dryRun` 1 for the preview. A plain controller rather than a resource, because the body is a file.
 *
 * Stateless on purpose: the preview keeps nothing, and the import is the same file sent again without `dryRun`, so it is
 * checked against the data as it is when it runs, never against a preview another member's changes have overtaken.
 *
 * 200 answers what was done or would be; 422 a file refused whole (`error`, `columns`) or an import that stopped on
 * rejected rows (the report, `committed` false), in which case nothing was stored.
 */
#[AsController]
final readonly class ImportController
{
    public function __construct(
        private ImportCatalogue $catalogue,
        private RunImport $import,
        private SpreadsheetReader $reader,
        private CompanyGuard $guard,
    ) {
    }

    #[Route('/api/companies/{companyId}/imports/{subject}', name: 'api_import', requirements: ['subject' => '[a-z-]+'], methods: ['POST'])]
    public function __invoke(string $companyId, string $subject, Request $request, #[Autowire(service: 'profiler')] ?Profiler $profiler = null): Response
    {
        // Symfony's documented way to keep one action out of the profiler, which only development has (null elsewhere): it keeps
        // every query of the request, and saving them for 2000 rows ran past the memory limit after the answer was sent.
        $profiler?->disable();

        try {
            $declaration = $this->catalogue->declaration($subject);
            $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), $declaration->permission());
            $columns = $this->catalogue->subject($subject, $company);
        } catch (UnknownImportSubject $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        }

        $file = $request->files->get('file');
        $mode = ImportMode::tryFrom($request->request->getString('mode', ImportMode::Create->value));
        $format = $file instanceof UploadedFile ? SpreadsheetFormat::ofFilename($file->getClientOriginalName()) : null;
        if (!$file instanceof UploadedFile || !$file->isValid() || null === $format || null === $mode) {
            return new JsonResponse(['error' => 'invalid_request'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dryRun = $request->request->getBoolean('dryRun');
        try {
            $report = $this->import->run(
                $declaration,
                $columns,
                $company,
                $this->reader->rows($file->getPathname(), $format),
                $mode,
                $dryRun,
                $this->guard->account()->getId(),
            );
        } catch (UnreadableSpreadsheet) {
            return new JsonResponse(['error' => UnreadableImport::UNREADABLE, 'columns' => []], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnreadableImport $refused) {
            return new JsonResponse(['error' => $refused->reason, 'columns' => $refused->columns, 'limit' => $refused->limit], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            ['committed' => $report->committed, 'created' => $report->created, 'updated' => $report->updated, 'rejected' => $report->rejected],
            // A preview that finds rejected rows has done its job; an import that stopped on them has not.
            $dryRun || $report->committed ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
