<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Infrastructure\Http;

use App\ModuleRegistry\Application\ModuleStates;
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
 * Deleting records nothing names, « Récemment supprimés » and its restore. Each refusal answers a code the page
 * translates. A subject no module declares, a module switched off and a missing write permission all answer the 404 a
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
        private RestoreRecords $restore,
        private ReadRemovals $read,
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
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), $subject->writePermission);
        $this->moduleOn($company, $subject);

        return $this->answer(fn (): Response => new JsonResponse($this->described($this->remove->handle($company, $subject, $body['ids'] ?? null, $this->guard->account()->getId())), Response::HTTP_CREATED));
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
        } catch (RemovalNoLongerPending) {
            return new JsonResponse(['error' => 'removal_final'], Response::HTTP_CONFLICT);
        } catch (RemovalConflict $conflict) {
            return new JsonResponse(['error' => 'restore_conflict', 'table' => $conflict->table], Response::HTTP_CONFLICT);
        } catch (UnknownRecords $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }
    }

    /** @return array{id: string, subject: string, items: list<array{id: string, label: string}>, removedAt: string, effectiveAt: string, state: string} */
    private function described(RecordRemoval $removal): array
    {
        return [
            'id' => $removal->getId()->toRfc4122(),
            'subject' => $removal->getSubject(),
            'items' => $removal->getItems(),
            'removedAt' => $removal->getRemovedAt()->format(\DateTimeInterface::ATOM),
            'effectiveAt' => $removal->getEffectiveAt()->format(\DateTimeInterface::ATOM),
            'state' => $removal->getState()->value,
        ];
    }
}
