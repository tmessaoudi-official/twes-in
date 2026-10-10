<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Infrastructure\Http;

use App\Erasure\Application\EraseData;
use App\Erasure\Application\ErasureCatalogue;
use App\Erasure\Application\ErasureConflict;
use App\Erasure\Application\ErasureNotFound;
use App\Erasure\Application\ErasurePending;
use App\Erasure\Application\NoPartChosen;
use App\Erasure\Application\OwnerOnly;
use App\Erasure\Application\ReadErasures;
use App\Erasure\Application\UndoErasure;
use App\Erasure\Application\UnknownErasurePart;
use App\Erasure\Domain\DataErasure;
use App\Erasure\Domain\ErasureNoLongerPending;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Tenancy\Domain\Company;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Effacer des données »: what each part would take, erasing, the erasure the banner offers, and its undo. Each refusal
 * answers a code the page translates. The company and the permission are asked first, so a stranger meets the same 404
 * as for any company of others; the owner and the proof of who is at the screen come after.
 */
#[AsController]
final readonly class DataErasureController
{
    /** What reaching the company's settings takes; only its owner goes further. */
    private const string PERMISSION = 'company.settings';

    public function __construct(
        private CompanyGuard $guard,
        private ReadErasures $read,
        private EraseData $erase,
        private UndoErasure $undo,
        private ErasureCatalogue $catalogue,
    ) {
    }

    #[Route('/api/companies/{companyId}/data-erasure', name: 'api_data_erasure_preview', methods: ['GET'])]
    public function preview(string $companyId): Response
    {
        $company = $this->company($companyId);

        return $this->answer(function () use ($company): Response {
            $parts = $this->read->preview($company, $this->guard->account()->getId());
            $pending = $this->read->pending($company, $this->guard->account()->getId());

            return new JsonResponse(['parts' => $parts, 'pending' => null === $pending ? null : $this->described($pending)]);
        });
    }

    #[Route('/api/companies/{companyId}/data-erasures', name: 'api_data_erasure_erase', methods: ['POST'])]
    public function erase(Request $request, string $companyId): Response
    {
        $company = $this->company($companyId);
        try {
            $body = $request->toArray();
        } catch (\Throwable $unreadable) {
            throw new BadRequestHttpException('The body is a JSON object naming the parts to erase.', $unreadable);
        }

        return $this->answer(fn (): Response => new JsonResponse($this->described($this->erase->handle($company, $this->guard->account()->getId(), $body['parts'] ?? null)), Response::HTTP_CREATED));
    }

    #[Route('/api/companies/{companyId}/data-erasures/pending', name: 'api_data_erasure_pending', methods: ['GET'])]
    public function pending(string $companyId): Response
    {
        $company = $this->company($companyId);

        return $this->answer(function () use ($company): Response {
            $pending = $this->read->pending($company, $this->guard->account()->getId());

            return null === $pending ? new Response(null, Response::HTTP_NO_CONTENT) : new JsonResponse($this->described($pending));
        });
    }

    #[Route('/api/companies/{companyId}/data-erasures/{erasureId}/undo', name: 'api_data_erasure_undo', methods: ['POST'])]
    public function undo(string $companyId, string $erasureId): Response
    {
        $company = $this->company($companyId);
        $id = CompanyPath::identifier(['erasureId' => $erasureId], 'erasureId');

        return $this->answer(fn (): Response => new JsonResponse($this->described($this->undo->handle($company, $this->guard->account()->getId(), $id))));
    }

    private function company(string $companyId): Company
    {
        return $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), self::PERMISSION);
    }

    /** @param callable(): Response $work */
    private function answer(callable $work): Response
    {
        try {
            return $work();
        } catch (OwnerOnly) {
            return new JsonResponse(['error' => 'owner_only'], Response::HTTP_FORBIDDEN);
        } catch (StepUpRequired) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        } catch (NoPartChosen) {
            return new JsonResponse(['error' => 'no_part'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnknownErasurePart $unknown) {
            return new JsonResponse(['error' => 'unknown_part', 'part' => $unknown->part], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ErasurePending $pending) {
            return new JsonResponse(['error' => 'erasure_pending', 'id' => $pending->erasureId->toRfc4122()], Response::HTTP_CONFLICT);
        } catch (ErasureNoLongerPending) {
            return new JsonResponse(['error' => 'erasure_final'], Response::HTTP_CONFLICT);
        } catch (ErasureConflict $conflict) {
            return new JsonResponse(['error' => 'erasure_conflict', 'table' => $conflict->table], Response::HTTP_CONFLICT);
        } catch (ErasureNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }
    }

    /** @return array{id: string, parts: list<string>, counts: array<string, array<string, int>>, erasedAt: string, effectiveAt: string, state: string, kinds: list<string>} */
    private function described(DataErasure $erasure): array
    {
        return [
            'id' => $erasure->getId()->toRfc4122(),
            'parts' => $erasure->getParts(),
            'counts' => $erasure->getCounts(),
            'erasedAt' => $erasure->getErasedAt()->format(\DateTimeInterface::ATOM),
            'effectiveAt' => $erasure->getEffectiveAt()->format(\DateTimeInterface::ATOM),
            'state' => $erasure->getState()->value,
            // What open screens read again once it went or came back: the tab that asked hears no change of its own.
            'kinds' => $this->catalogue->liveKindsOf($erasure->getParts()),
        ];
    }
}
