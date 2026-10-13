<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Http;

use App\Identity\Application\StepUp\StepUpRequired;
use App\Tenancy\Application\Support\ManageSupportAccess;
use App\Tenancy\Application\Support\OwnerOnly;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\InvalidSupportWindow;
use App\Tenancy\Domain\SupportGrant;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Accès support »: whether the company is open to support and until when, opening it, and closing it early. The
 * company and the permission are asked first, so a stranger meets the same 404 as for any company of others; only its
 * owner goes further, which answers a code the page translates. Opening it asks the owner for a recent proof of who is
 * at the screen (`step_up_required`).
 */
#[AsController]
final readonly class SupportAccessController
{
    /** What reaching the company's settings takes; only its owner goes further. */
    private const string PERMISSION = 'company.settings';

    public function __construct(private CompanyGuard $guard, private ManageSupportAccess $support)
    {
    }

    #[Route('/api/companies/{companyId}/support-access', name: 'api_support_access_read', methods: ['GET'])]
    public function read(string $companyId): Response
    {
        $company = $this->company($companyId);

        return $this->answer(fn (): Response => new JsonResponse(self::described($this->support->current($company, $this->guard->account()->getId()))));
    }

    #[Route('/api/companies/{companyId}/support-access', name: 'api_support_access_grant', methods: ['POST'])]
    public function grant(Request $request, string $companyId): Response
    {
        $company = $this->company($companyId);
        try {
            $body = $request->toArray();
        } catch (\Throwable $unreadable) {
            throw new BadRequestHttpException('The body is a JSON object naming the hours.', $unreadable);
        }

        return $this->answer(fn (): Response => new JsonResponse(self::described($this->support->grant($company, $this->guard->account()->getId(), $body['hours'] ?? null)), Response::HTTP_CREATED));
    }

    #[Route('/api/companies/{companyId}/support-access', name: 'api_support_access_end', methods: ['DELETE'])]
    public function end(string $companyId): Response
    {
        $company = $this->company($companyId);

        return $this->answer(function () use ($company): Response {
            $this->support->end($company, $this->guard->account()->getId());

            return new Response(null, Response::HTTP_NO_CONTENT);
        });
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
        } catch (InvalidSupportWindow) {
            return new JsonResponse(['error' => 'support_window', 'hours' => SupportGrant::HOURS], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (StepUpRequired) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        }
    }

    /** @return array{startsAt: ?string, endsAt: ?string, hours: list<int>} */
    private static function described(?SupportGrant $grant): array
    {
        return [
            'startsAt' => $grant?->getStartsAt()->format(\DateTimeInterface::ATOM),
            'endsAt' => $grant?->getEndsAt()->format(\DateTimeInterface::ATOM),
            'hours' => SupportGrant::HOURS,
        ];
    }
}
