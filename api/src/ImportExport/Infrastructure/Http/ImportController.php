<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Http;

use App\Identity\Application\StepUp\StepUpRequired;
use App\ImportExport\Application\ImportCatalogue;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\RunImport;
use App\ImportExport\Application\UnknownImportSubject;
use App\ImportExport\Application\UnreadableImport;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\SpreadsheetReader;
use App\Shared\Application\Spreadsheet\TextEncoding;
use App\Shared\Application\Spreadsheet\UnreadableSpreadsheet;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
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
 * upsert, `dryRun` 1 for the preview, `switches[]` each switch of the subject ticked, `pairing` a JSON object pairing
 * the file's own headings with column keys ('' for a column ignored), sent whole because a heading may hold any
 * character a form field's name would lose. A plain controller rather than a resource, because the body is a file.
 *
 * Stateless on purpose: the preview keeps nothing, and the import is the same file sent again without `dryRun`, so it is
 * checked against the data as it is when it runs, never against a preview another member's changes have overtaken.
 *
 * 200 answers what was done or would be; 422 a file refused whole (`error`, `columns`) or an import that stopped on
 * rejected rows (the report, `committed` false), in which case nothing was stored; 403 `step_up_required` an import
 * that changes what asks who is at the screen again (a vendor's bank details), kept nothing, and is sent again once
 * the person proved it.
 */
#[AsController]
final readonly class ImportController
{
    public function __construct(
        private ImportCatalogue $catalogue,
        private RunImport $import,
        private SpreadsheetReader $reader,
        private CompanyGuard $guard,
        #[Autowire(param: 'app.import.max_seconds')]
        private int $maxSeconds,
    ) {
    }

    #[Route('/api/companies/{companyId}/imports/{subject}', name: 'api_import', requirements: ['subject' => '[a-z-]+'], methods: ['POST'])]
    public function __invoke(string $companyId, string $subject, Request $request, #[Autowire(service: 'profiler')] ?Profiler $profiler = null): Response
    {
        // Symfony's documented way to keep one action out of the profiler, which only development has (null elsewhere): it keeps
        // every query of the request, and saving them for 2000 rows ran past the memory limit after the answer was sent.
        $profiler?->disable();
        // One file is one unit of work, run inside this request; PHP's limit (none is 0) is lifted to what the largest
        // file allowed may take, never shortened.
        $limit = (int) \ini_get('max_execution_time');
        if (0 !== $limit && $limit < $this->maxSeconds) {
            set_time_limit($this->maxSeconds);
        }

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

        // A CSV's encoding, as the person chose it; none chosen is found on the file. A workbook has none to choose.
        $chosen = $request->request->getString('encoding');
        $encoding = '' === $chosen ? null : TextEncoding::tryFrom($chosen);
        if ('' !== $chosen && null === $encoding) {
            return new JsonResponse(['error' => 'invalid_request'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $encoding = SpreadsheetFormat::Csv === $format ? ($encoding ?? $this->reader->encodingOf($file->getPathname(), $format)) : null;

        $dryRun = $request->request->getBoolean('dryRun');
        $switches = [];
        foreach ($request->request->all('switches') as $switch) {
            if (!\is_string($switch)) {
                return new JsonResponse(['error' => 'invalid_request'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $switches[] = $switch;
        }
        $pairing = $this->pairing($request->request->getString('pairing'));
        if (null === $pairing) {
            return new JsonResponse(['error' => 'invalid_request'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // The file's bytes, so the same file sent again is known whatever it is named.
        $contentHash = hash_file('sha256', $file->getPathname());
        if (false === $contentHash) {
            return new JsonResponse(['error' => 'invalid_request'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $report = $this->import->run(
                $declaration,
                $columns,
                $company,
                $this->reader->rows($file->getPathname(), $format, $encoding),
                $mode,
                $dryRun,
                $this->guard->account()->getId(),
                $contentHash,
                $switches,
                $pairing,
            );
        } catch (UnreadableSpreadsheet) {
            return new JsonResponse(['error' => UnreadableImport::UNREADABLE, 'columns' => [], 'encoding' => $encoding?->value], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnreadableImport $refused) {
            return new JsonResponse(['error' => $refused->reason, 'columns' => $refused->columns, 'limit' => $refused->limit, 'encoding' => $encoding?->value], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (StepUpRequired) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(
            ['committed' => $report->committed, 'created' => $report->created, 'updated' => $report->updated, 'unchanged' => $report->unchanged, 'rejected' => $report->rejected, 'changes' => $report->changes, 'notes' => $report->notes, 'alreadyImportedAt' => $report->alreadyImportedAt?->format(\DATE_ATOM), 'encoding' => $encoding?->value],
            // A preview that finds rejected rows has done its job; an import that stopped on them has not.
            $dryRun || $report->committed ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * A JSON object of headings to column keys, every value a string; none sent is no pairing.
     *
     * @return array<string, string>|null null when it is anything else
     */
    private function pairing(string $sent): ?array
    {
        if ('' === $sent) {
            return [];
        }
        try {
            $decoded = json_decode($sent, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        // An object decodes to an array with string keys; a list, or an empty one, is told apart by its opening brace.
        if (!\is_array($decoded) || !str_starts_with(ltrim($sent), '{')) {
            return null;
        }
        $pairing = [];
        foreach ($decoded as $heading => $column) {
            if (!\is_string($column)) {
                return null;
            }
            $pairing[(string) $heading] = $column;
        }

        return $pairing;
    }
}
