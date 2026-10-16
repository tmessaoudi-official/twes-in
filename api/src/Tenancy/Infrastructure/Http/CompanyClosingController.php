<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Http;

use App\Identity\Application\StepUp\StepUpRequired;
use App\Tenancy\Application\Closing\CloseDownCompany;
use App\Tenancy\Application\Closing\NotClosedDown;
use App\Tenancy\Application\Closing\ReopenPeriodOver;
use App\Tenancy\Application\Member\OwnerOnly;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Fermer l'entreprise » and taking it back. The company and the permission are asked first, so a stranger meets the
 * same 404 as for any company of others; only its owner goes further, and each refusal answers a code the page translates.
 */
#[AsController]
final readonly class CompanyClosingController
{
    /** What reaching the company's settings takes; only its owner goes further. */
    private const string PERMISSION = 'company.settings';

    public function __construct(private CompanyGuard $guard, private CloseDownCompany $closing)
    {
    }

    #[Route('/api/companies/{companyId}/close-down', name: 'api_company_close_down', methods: ['POST'])]
    public function closeDown(string $companyId): Response
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), self::PERMISSION);

        return $this->answer(function () use ($company): Response {
            $closed = $this->closing->closeDown($company, $this->guard->account()->getId());

            return new JsonResponse(['retainUntil' => $closed->retainUntil->format('Y-m-d'), 'reopenUntil' => $closed->reopenUntil->format(\DATE_ATOM)]);
        });
    }

    #[Route('/api/companies/{companyId}/reopen', name: ClosedCompanyListener::REOPEN_ROUTE, methods: ['POST'])]
    public function reopen(string $companyId): Response
    {
        $company = $this->guard->companyToReopen(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), self::PERMISSION);

        return $this->answer(function () use ($company): Response {
            $this->closing->reopen($company, $this->guard->account()->getId());

            return new Response(null, Response::HTTP_NO_CONTENT);
        });
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
        } catch (ReopenPeriodOver) {
            return new JsonResponse(['error' => 'reopen_period_over'], Response::HTTP_CONFLICT);
        } catch (NotClosedDown) {
            return new JsonResponse(['error' => 'not_closed_down'], Response::HTTP_CONFLICT);
        }
    }
}
