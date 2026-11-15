<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Http;

use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Tenancy\Application\Member\MemberDevices;
use App\Tenancy\Application\Member\MemberSession;
use App\Tenancy\Application\Member\NotAMember;
use App\Tenancy\Application\Member\OwnerOnly;
use App\Tenancy\Application\Member\OwnMembership;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\Security\MemberPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * « Appareils connectés » of a member, read and ended by the company's owner. The company and the permission are asked
 * first, so a stranger meets the same 404 as for any company of others; then who may, before any proof is asked; and
 * ending one, a change to how a person signs in, waits for a recent proof as changing a member does. Each refusal is a
 * code the dialog says, as a plain controller's must be: none of them is API Platform's to put into words.
 */
#[AsController]
final readonly class MemberSessionsController
{
    private const string UUID = '[0-9a-fA-F-]{36}';

    public function __construct(private CompanyGuard $guard, private MemberDevices $devices, private ConfirmStepUp $stepUp)
    {
    }

    #[Route('/api/companies/{companyId}/members/{userId}/sessions', name: 'api_member_sessions', methods: ['GET'], requirements: ['userId' => self::UUID])]
    public function list(string $companyId, string $userId): Response
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), MemberPermission::READ);

        return $this->answer(fn (): Response => new JsonResponse(array_map(static fn (MemberSession $session): array => [
            'id' => $session->id->toRfc4122(),
            'device' => $session->device,
            'address' => $session->address,
            'createdAt' => $session->createdAt->format(\DATE_ATOM),
            'lastSeenAt' => $session->lastSeenAt->format(\DATE_ATOM),
        ], $this->devices->of($company->getId(), Uuid::fromString($userId), $this->guard->account()->getId()))));
    }

    #[Route('/api/companies/{companyId}/members/{userId}/sessions/{sessionId}', name: 'api_member_sessions_end', methods: ['DELETE'], requirements: ['userId' => self::UUID, 'sessionId' => self::UUID])]
    public function end(string $companyId, string $userId, string $sessionId): Response
    {
        return $this->ending($companyId, $userId, Uuid::fromString($sessionId), static fn (int $ended): Response => 0 === $ended
            ? new JsonResponse(['error' => 'session_not_found'], Response::HTTP_NOT_FOUND)
            : new Response(null, Response::HTTP_NO_CONTENT));
    }

    #[Route('/api/companies/{companyId}/members/{userId}/sessions', name: 'api_member_sessions_end_all', methods: ['DELETE'], requirements: ['userId' => self::UUID])]
    public function endAll(string $companyId, string $userId): Response
    {
        return $this->ending($companyId, $userId, null, static fn (int $ended): Response => new JsonResponse(['ended' => $ended]));
    }

    /** @param callable(int): Response $answered */
    private function ending(string $companyId, string $userId, ?Uuid $sessionId, callable $answered): Response
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), MemberPermission::WRITE);
        $member = Uuid::fromString($userId);
        $actor = $this->guard->account()->getId();

        return $this->answer(function () use ($company, $member, $sessionId, $actor, $answered): Response {
            // What the role refuses is said first: nobody proves who they are only to be told no.
            $this->devices->assertMay($company->getId(), $member, $actor);
            if (!$this->stepUp->isRecent($actor)) {
                return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
            }

            return $answered($this->devices->end($company->getId(), $member, $sessionId, $actor));
        });
    }

    /** @param callable(): Response $work */
    private function answer(callable $work): Response
    {
        try {
            return $work();
        } catch (NotAMember) {
            return new JsonResponse(['error' => 'member_not_found'], Response::HTTP_NOT_FOUND);
        } catch (OwnerOnly) {
            return new JsonResponse(['error' => 'owner_only'], Response::HTTP_FORBIDDEN);
        } catch (OwnMembership) {
            return new JsonResponse(['error' => 'own_sessions'], Response::HTTP_FORBIDDEN);
        }
    }
}
