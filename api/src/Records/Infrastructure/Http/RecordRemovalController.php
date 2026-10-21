<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Infrastructure\Http;

use App\Identity\Application\StepUp\StepUpRequired;
use App\ModuleRegistry\Application\ModuleStates;
use App\Records\Application\Merge\MergeActor;
use App\Records\Application\Merge\MergeAlias;
use App\Records\Application\Merge\MergeConflict;
use App\Records\Application\Merge\MergeCycle;
use App\Records\Application\Merge\MergeIntoItself;
use App\Records\Application\Merge\MergeMismatch;
use App\Records\Application\Merge\MergeRecords;
use App\Records\Application\Merge\MergeRefused;
use App\Records\Application\Merge\MergeTransacted;
use App\Records\Application\Merge\PreviewMerge;
use App\Records\Application\NoRecordChosen;
use App\Records\Application\ReadRemovals;
use App\Records\Application\RecordCatalogue;
use App\Records\Application\RecordsInUse;
use App\Records\Application\RecordSubject;
use App\Records\Application\RemovalConflict;
use App\Records\Application\RemoveRecords;
use App\Records\Application\RestoreRecords;
use App\Records\Application\UnknownRecords;
use App\Records\Domain\Exception\RemovalNoLongerPending;
use App\Records\Domain\Model\RecordRemoval;
use App\Shared\Infrastructure\Http\LongRequest;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Deleting records nothing names, merging records into one, « Récemment supprimés » and its restore, which undoes a
 * merge too. Each refusal answers a code the page translates. A subject no module declares, a module switched off and a missing write permission all answer the 404 a
 * stranger meets, as the subject's own resources do.
 */
#[AsController]
final readonly class RecordRemovalController
{
    /** Anyone who may read the company reaches the page; each delete then asks its subject's write permission. */
    private const string PERMISSION = 'company.read';

    public function __construct(
        private CompanyGuard $guard,
        private RecordCatalogue $catalogue,
        private ModuleStates $modules,
        private RemoveRecords $remove,
        private MergeRecords $merge,
        private RestoreRecords $restore,
        private ReadRemovals $read,
        private PreviewMerge $preview,
        private LongRequest $time,
    ) {
    }

    #[Route('/api/companies/{companyId}/record-removals', name: 'api_record_removal_remove', methods: ['POST'])]
    public function remove(Request $request, string $companyId): Response
    {
        try {
            $body = $request->toArray();
        } catch (\Throwable $unreadable) {
            throw new BadRequestHttpException('The body is a JSON object naming the subject and the ids to delete.', $unreadable);
        }
        try {
            $subject = $this->catalogue->subject(\is_string($body['subject'] ?? null) ? $body['subject'] : '');
        } catch (UnknownRecords $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        }
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), $subject->removePermission);
        $this->moduleOn($company, $subject);

        return $this->answer(fn (): Response => new JsonResponse($this->described($this->remove->handle($company, $subject, $body['ids'] ?? null, $this->guard->account()->getId())), Response::HTTP_CREATED));
    }

    #[Route('/api/companies/{companyId}/record-merges', name: 'api_record_removal_merge', methods: ['POST'])]
    public function merge(Request $request, string $companyId): Response
    {
        try {
            $body = $request->toArray();
        } catch (\Throwable $unreadable) {
            throw new BadRequestHttpException('The body is a JSON object naming the subject, the record kept and the ids merged into it.', $unreadable);
        }
        try {
            $subject = $this->catalogue->subject(\is_string($body['subject'] ?? null) ? $body['subject'] : '');
        } catch (UnknownRecords $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        }
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), $subject->removePermission);
        $this->moduleOn($company, $subject);

        return $this->answer(fn (): Response => new JsonResponse($this->described($this->merge->handle($company, $subject, $body['keptId'] ?? null, $body['ids'] ?? null, new MergeActor($this->guard->account()->getId(), fn (string $permission): bool => $this->guard->may($company, $permission)))), Response::HTTP_CREATED));
    }

    /** What merging the records would move and leave, asked of the subject's writer as merging is. */
    #[Route('/api/companies/{companyId}/record-merges/preview', name: 'api_record_removal_merge_preview', methods: ['GET'])]
    public function previewMerge(Request $request, string $companyId): Response
    {
        try {
            $subject = $this->catalogue->subject($request->query->getString('subject'));
        } catch (UnknownRecords $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        }
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), $subject->removePermission);
        $this->moduleOn($company, $subject);
        $ids = $request->query->all()['ids'] ?? null;

        return $this->answer(fn (): Response => new JsonResponse($this->preview->handle($company, $subject, $ids)));
    }

    #[Route('/api/companies/{companyId}/record-removals', name: 'api_record_removal_list', methods: ['GET'])]
    public function list(string $companyId): Response
    {
        $company = $this->company($companyId);
        $subjects = array_keys(array_filter($this->catalogue->subjects(), fn (RecordSubject $subject): bool => $this->mayWrite($company, $subject)));

        return new JsonResponse(array_map($this->described(...), $this->read->pending($company, $subjects)));
    }

    #[Route('/api/companies/{companyId}/record-removals/{removalId}/restore', name: 'api_record_removal_restore', methods: ['POST'])]
    public function restore(string $companyId, string $removalId): Response
    {
        $company = $this->company($companyId);
        $id = CompanyPath::identifier(['removalId' => $removalId], 'removalId');
        // What an import's undo took is put back whole, in this request.
        $this->time->lift();

        return $this->answer(function () use ($company, $id): Response {
            $subject = $this->catalogue->subject($this->read->one($company, $id)->getSubject());
            if (!$this->mayWrite($company, $subject)) {
                throw new UnknownRecords('The person may not write what this delete took.');
            }

            return new JsonResponse($this->described($this->restore->handle($company, $id, $this->guard->account()->getId())));
        });
    }

    private function company(string $companyId): Company
    {
        return $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), self::PERMISSION);
    }

    private function mayWrite(Company $company, RecordSubject $subject): bool
    {
        return $this->guard->may($company, $subject->writePermission) && (null === $subject->module || $this->modules->isEnabled($company->getId(), $subject->module));
    }

    private function moduleOn(Company $company, RecordSubject $subject): void
    {
        if (null !== $subject->module && !$this->modules->isEnabled($company->getId(), $subject->module)) {
            throw new NotFoundHttpException('The company keeps no such records.');
        }
    }

    /** @param callable(): Response $work */
    private function answer(callable $work): Response
    {
        try {
            return $work();
        } catch (NoRecordChosen) {
            return new JsonResponse(['error' => 'no_record'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RecordsInUse $used) {
            return new JsonResponse(['error' => 'record_used', 'records' => $used->records], Response::HTTP_CONFLICT);
        } catch (RemovalNoLongerPending $over) {
            return new JsonResponse(['error' => $over->reason], Response::HTTP_CONFLICT);
        } catch (RemovalConflict $conflict) {
            return new JsonResponse(['error' => 'restore_conflict', 'table' => $conflict->table], Response::HTTP_CONFLICT);
        } catch (MergeIntoItself) {
            return new JsonResponse(['error' => 'merge_into_itself'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MergeCycle) {
            return new JsonResponse(['error' => 'merge_cycle'], Response::HTTP_CONFLICT);
        } catch (MergeConflict $conflict) {
            return new JsonResponse(['error' => 'merge_conflict', 'table' => $conflict->table], Response::HTTP_CONFLICT);
        } catch (MergeAlias) {
            return new JsonResponse(['error' => 'merge_alias'], Response::HTTP_CONFLICT);
        } catch (MergeMismatch $mismatch) {
            return new JsonResponse(['error' => 'merge_mismatch', 'column' => $mismatch->column], Response::HTTP_CONFLICT);
        } catch (MergeRefused $refused) {
            return new JsonResponse(['error' => 'merge_refused', 'reason' => $refused->reason, ...$refused->params], Response::HTTP_CONFLICT);
        } catch (MergeTransacted $traded) {
            return new JsonResponse(['error' => 'merge_transacted', 'uses' => $traded->uses], Response::HTTP_CONFLICT);
        } catch (StepUpRequired) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        } catch (UnknownRecords $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }
    }

    /** @return array{id: string, kind: string, subject: string, kept: array{id: string, label: string}|null, items: list<array{id: string, label: string}>, removedAt: string, effectiveAt: string, state: string} */
    private function described(RecordRemoval $removal): array
    {
        return [
            'id' => $removal->getId()->toRfc4122(),
            'kind' => $removal->getKind()->value,
            'subject' => $removal->getSubject(),
            'kept' => $removal->kept(),
            'items' => $removal->getItems(),
            'removedAt' => $removal->getRemovedAt()->format(\DateTimeInterface::ATOM),
            'effectiveAt' => $removal->getEffectiveAt()->format(\DateTimeInterface::ATOM),
            'state' => $removal->getState()->value,
        ];
    }
}
