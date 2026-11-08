<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Http;

use ApiPlatform\Validator\Exception\ValidationException;
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
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Shared\Infrastructure\Http\LongRequest;
use App\Shared\Infrastructure\Http\ReceivedFile;
use App\Shared\Infrastructure\Submissions\Submissions;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Previews or imports one file (docs/SPEC.md § 7, 2026-09-17 and 2026-09-19): `file` a .csv or .xlsx, `mode` create or
 * upsert, `dryRun` 1 for the preview, `switches[]` each switch of the subject ticked, `choices[<switch>]` the answer of a
 * switch that is a choice (its first when none is sent, 422 for one it does not offer), `pairing` a JSON object pairing
 * the file's own headings with column keys ('' for a column ignored), sent whole because a heading may hold any
 * character a form field's name would lose. A plain controller rather than a resource, because the body is a file.
 *
 * Stateless on purpose: the preview keeps nothing, and the import is the same file sent again without `dryRun`, so it is
 * checked against the data as it is when it runs, never against a preview another member's changes have overtaken.
 *
 * 200 answers what was done or would be; 422 a value sent refused (a violation on `file`, `mode`, `encoding`, `switches`,
 * `choices` or `pairing`, with its reason), a file refused whole (`error`, `columns`) or an import that stopped on
 * rejected rows (the report, `committed` false), in which case nothing was stored; 403 `step_up_required` an import
 * that changes what asks who is at the screen again (a vendor's bank details), kept nothing, and is sent again once
 * the person proved it.
 */
#[AsController]
final readonly class ImportController
{
    /** A file of a format the import does not read, refused on the file. */
    public const string FILE_FORMAT = 'file_format';
    /** A value only the screen builds that is none the import offers, refused on its field. */
    public const string NOT_OFFERED = 'unknown_choice';

    public function __construct(
        private ImportCatalogue $catalogue,
        private RunImport $import,
        private SpreadsheetReader $reader,
        private CompanyGuard $guard,
        private LongRequest $time,
    ) {
    }

    #[Route('/api/companies/{companyId}/imports/{subject}', name: 'api_import', requirements: ['subject' => '[a-z-]+'], defaults: [Submissions::ROUTE_DEFAULT => true, '_api_respond' => true], methods: ['POST'])]
    public function __invoke(string $companyId, string $subject, Request $request, #[Autowire(service: 'profiler')] ?Profiler $profiler = null): Response
    {
        // Symfony's documented way to keep one action out of the profiler, which only development has (null elsewhere): it keeps
        // every query of the request, and saving them for 2000 rows ran past the memory limit after the answer was sent.
        $profiler?->disable();
        // One file is one unit of work, run inside this request.
        $this->time->lift();

        try {
            $declaration = $this->catalogue->declaration($subject);
            $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), $declaration->permission());
            $columns = $this->catalogue->subject($subject, $company);
        } catch (UnknownImportSubject $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        }

        $file = ReceivedFile::of($request);
        $format = SpreadsheetFormat::ofFilename($file->getClientOriginalName())
            ?? throw DomainRule::refused(ReceivedFile::PART, 'An import reads a .csv or a .xlsx file.', self::FILE_FORMAT);
        $mode = ImportMode::tryFrom($request->request->getString('mode', ImportMode::Create->value)) ?? throw self::notOffered('mode');

        // A CSV's encoding, as the person chose it; none chosen is found on the file. A workbook has none to choose.
        $chosen = $request->request->getString('encoding');
        $encoding = '' === $chosen ? null : TextEncoding::tryFrom($chosen);
        if ('' !== $chosen && null === $encoding) {
            throw self::notOffered('encoding');
        }
        $encoding = SpreadsheetFormat::Csv === $format ? ($encoding ?? $this->reader->encodingOf($file->getPathname(), $format)) : null;

        $dryRun = $request->request->getBoolean('dryRun');
        $switches = [];
        foreach ($request->request->all('switches') as $switch) {
            if (!\is_string($switch)) {
                throw self::notOffered('switches');
            }
            $switches[] = $switch;
        }
        $named = [];
        foreach ($request->request->all('choices') as $key => $answer) {
            if (!\is_string($answer)) {
                throw self::notOffered('choices');
            }
            $named[(string) $key] = $answer;
        }
        $answers = $columns->chosen($named) ?? throw self::notOffered('choices');
        $pairing = $this->pairing($request->request->getString('pairing')) ?? throw self::notOffered('pairing');
        // The file's bytes, so the same file sent again is known whatever it is named; bytes that cannot be read back
        // did not arrive whole.
        $contentHash = hash_file('sha256', $file->getPathname())
            ?: throw DomainRule::refused(ReceivedFile::PART, 'The file could not be read back.', ReceivedFile::NOT_RECEIVED);
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
                $answers,
            );
        } catch (UnreadableSpreadsheet $unreadable) {
            // error-codes: UnreadableSpreadsheet
            return new JsonResponse(['error' => $unreadable->reason, 'columns' => [], 'encoding' => $encoding?->value], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnreadableImport $refused) {
            // error-codes: UnreadableImport
            return new JsonResponse(['error' => $refused->reason, 'columns' => $refused->columns, 'limit' => $refused->limit, 'encoding' => $encoding?->value], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (StepUpRequired) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(
            ['committed' => $report->committed, 'created' => $report->created, 'updated' => $report->updated, 'unchanged' => $report->unchanged, 'rejected' => $report->rejected, 'changes' => $report->changes, 'notes' => $report->notes, 'alreadyImportedAt' => $report->alreadyImportedAt?->format(\DATE_ATOM), 'encoding' => $encoding?->value, 'run' => $report->run?->toRfc4122()],
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

    /** A value only the screen builds (the mode, an encoding, a switch, a choice, the pairing), refused on its field. */
    private static function notOffered(string $field): ValidationException
    {
        return DomainRule::refused($field, \sprintf('The %s sent is not one this import offers.', $field), self::NOT_OFFERED);
    }
}
