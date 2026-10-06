<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Http;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\ImportExport\Application\ExportCatalogue;
use App\ImportExport\Application\ExportQuery;
use App\ImportExport\Application\UnknownExportSubject;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\SpreadsheetWriter;
use App\Shared\Application\Spreadsheet\UnwritableSpreadsheet;
use App\Shared\Domain\InvalidFilter;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A list as a file, under the search, filters and order its screen shows (docs/SPEC.md § 7, 2026-09-17 and row 60). A
 * plain controller rather than a resource: the answer is bytes, not JSON. The file is written to disk row by row and
 * served from there, so a list of any length costs one row of memory, and removed once sent.
 *
 * A whole list leaves the company in one file, so it waits for the person to have proved who they are in the last few
 * minutes (docs/SPEC.md § 7, audit H-b2), and every file handed out is on record with what it held. The record's kind is
 * `export`, which no screen reloads on.
 */
#[AsController]
final readonly class ExportController
{
    public function __construct(
        private ExportCatalogue $catalogue,
        private SpreadsheetWriter $writer,
        private CompanyGuard $guard,
        private Security $security,
        private ConfirmStepUp $stepUp,
        private AuditTrail $audit,
    ) {
    }

    #[Route('/api/companies/{companyId}/exports/{subject}.{format}', name: 'api_export', requirements: ['subject' => '[a-z-]+', 'format' => 'csv|xlsx'], methods: ['GET'])]
    public function __invoke(Request $request, string $companyId, string $subject, string $format): Response
    {
        try {
            $permission = $this->catalogue->declaration($subject)->permission();
            $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), $permission);
            $declaration = $this->catalogue->declarationFor($subject, $company);
        } catch (UnknownExportSubject $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        }

        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // companyForActing has already refused anyone not signed in, so this is a contradiction, not a user error.
            throw new \LogicException('An export runs behind the firewall.');
        }
        // Asked after the company and the permission, so a stranger still meets the same 404 as for any company of others.
        if (!$this->stepUp->isRecent($account->getId())) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        }

        $spreadsheet = SpreadsheetFormat::from($format);
        // The writer's contract is a path, because a .xlsx is a zip archive and a zip has to be seekable.
        $path = tempnam(sys_get_temp_dir(), 'twes-export-');
        if (false === $path) {
            throw new ServiceUnavailableHttpException(30, 'The file could not be prepared; try again shortly.');
        }

        try {
            $query = new ExportQuery($request->query->all());
            $rows = (static function () use ($declaration, $company, $query): \Generator {
                yield $declaration->columns($company);
                yield from $declaration->rows($company, $query);
            })();
            $this->writer->write($path, $spreadsheet, $rows);
        } catch (UnwritableSpreadsheet $failure) {
            @unlink($path);
            // The writer reads the rows lazily and wraps whatever stops it: a filter the list does not have is the caller's.
            if ($failure->getPrevious() instanceof InvalidFilter) {
                throw new UnprocessableEntityHttpException($failure->getPrevious()->getMessage(), $failure->getPrevious());
            }
            throw new ServiceUnavailableHttpException(30, 'The file could not be prepared; try again shortly.', $failure);
        } catch (InvalidFilter $refused) {
            @unlink($path);
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        } catch (\Throwable $failure) {
            @unlink($path);
            throw $failure;
        }

        $this->audit->record(new AuditEntry('export', $company->getId(), 'export.downloaded', $account->getId(), ['subject' => $subject, 'format' => $format, 'filters' => array_keys($request->query->all())], $company->getId()));

        $response = new BinaryFileResponse($path, Response::HTTP_OK, [
            'Content-Type' => $spreadsheet->mediaType(),
            'Cache-Control' => 'private, no-store',
        ]);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('%s.%s', $subject, $spreadsheet->extension()));
        $response->deleteFileAfterSend();

        return $response;
    }
}
