<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Http;

use App\Identity\Application\StepUp\StepUpRequired;
use App\ImportExport\Application\ImportCatalogue;
use App\ImportExport\Application\Undo\ImportNotUndoable;
use App\ImportExport\Application\Undo\ImportUndone;
use App\ImportExport\Application\Undo\ReadImportRuns;
use App\ImportExport\Application\Undo\UndoImport;
use App\ImportExport\Application\Undo\UnknownImportRun;
use App\ImportExport\Application\UnknownImportSubject;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Imports récents » and « Annuler cet import ». An import into a subject the person may not write, or whose module
 * the company switched off, answers the 404 a stranger meets, as each subject's own resources and deletes do.
 *
 * The undo answers 200 with what it did; 409 `import_undone` once taken back, `import_final` once its day is over;
 * 403 `step_up_required` without a proof given again a few minutes ago.
 */
#[AsController]
final readonly class ImportRunController
{
    /** Anyone who may read the company reaches the list; each import shows only to whoever may write its subject. */
    private const string PERMISSION = 'company.read';

    public function __construct(
        private CompanyGuard $guard,
        private ImportCatalogue $catalogue,
        private ReadImportRuns $read,
        private UndoImport $undo,
        private ModuleStates $modules,
    ) {
    }

    #[Route('/api/companies/{companyId}/import-runs', name: 'api_import_runs', methods: ['GET'])]
    public function list(string $companyId): Response
    {
        $company = $this->company($companyId);
        $subjects = array_values(array_filter($this->catalogue->keys(), fn (string $key): bool => $this->mayWrite($company, $key)));

        return new JsonResponse(array_map(static fn (array $recent): array => [
            'id' => $recent['run']->getId()->toRfc4122(),
            'subject' => $recent['run']->getSubject(),
            'at' => $recent['run']->getAt()->format(\DATE_ATOM),
            'by' => $recent['by'],
            'created' => $recent['run']->getCreated(),
            'updated' => $recent['run']->getUpdated(),
            'undoableUntil' => $recent['undoableUntil']->format(\DATE_ATOM),
            'undoneAt' => $recent['run']->getUndoneAt()?->format(\DATE_ATOM),
        ], $this->read->recent($company, $subjects)));
    }

    #[Route('/api/companies/{companyId}/import-runs/{runId}/undo', name: 'api_import_run_undo', methods: ['POST'])]
    public function undo(string $companyId, string $runId): Response
    {
        $company = $this->company($companyId);
        $id = CompanyPath::identifier(['runId' => $runId], 'runId');
        try {
            if (!$this->mayWrite($company, $this->read->subjectOf($company, $id))) {
                throw new UnknownImportRun('The person may not write what this import wrote.');
            }

            return new JsonResponse(self::described($this->undo->handle($company, $id, $this->guard->account()->getId())));
        } catch (UnknownImportRun $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (ImportNotUndoable $refused) {
            return new JsonResponse(['error' => $refused->reason], Response::HTTP_CONFLICT);
        } catch (StepUpRequired) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        }
    }

    private function company(string $companyId): Company
    {
        return $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), self::PERMISSION);
    }

    /** Whether the person may write the subject, in a company whose module for it is on. */
    private function mayWrite(Company $company, string $subject): bool
    {
        try {
            $declaration = $this->catalogue->declaration($subject);
        } catch (UnknownImportSubject) {
            return false;
        }

        return $this->modules->isEnabled($company->getId(), $declaration->module()) && $this->guard->may($company, $declaration->permission());
    }

    /**
     * @return array{removal: string|null, removed: list<array{id: string, label: string}>, kept: list<array{id: string, label: string, uses: list<array{table: string, column: string, count: int}>}>, rowsKept: list<array{label: string, reason: string}>, stockTakenBack: list<array{reference: string, location: string, quantity: string}>, stockKept: list<array{reference: string, location: string, reason: string}>, updated: int}
     */
    private static function described(ImportUndone $undone): array
    {
        return [
            'removal' => $undone->removal?->toRfc4122(),
            'removed' => $undone->removed,
            'kept' => $undone->kept,
            'rowsKept' => $undone->rowsKept,
            'stockTakenBack' => $undone->stockTakenBack,
            'stockKept' => $undone->stockKept,
            'updated' => $undone->updated,
        ];
    }
}
