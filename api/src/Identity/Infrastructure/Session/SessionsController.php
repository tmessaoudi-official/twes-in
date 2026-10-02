<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use App\Identity\Application\Session\ManageSessions;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The person's own connected devices: read them, end one, end all the others. Always about the signed-in account and
 * the session making the request, never an account named in the path.
 */
final readonly class SessionsController
{
    public function __construct(private Security $security, private UserRepository $users, private ManageSessions $sessions)
    {
    }

    #[Route('/api/auth/sessions', name: 'api_auth_sessions', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $user = $this->account();

        return new JsonResponse(array_map(static fn ($entry): array => [
            'id' => $entry->session->getId()->toRfc4122(),
            'device' => $entry->session->getDevice(),
            'address' => $entry->session->getAddress(),
            'createdAt' => $entry->session->getCreatedAt()->format(\DATE_ATOM),
            'lastSeenAt' => $entry->session->getLastSeenAt()->format(\DATE_ATOM),
            'current' => $entry->current,
        ], $this->sessions->listFor($user, $request->getSession()->getId())));
    }

    #[Route('/api/auth/sessions/{id}', name: 'api_auth_sessions_end', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function end(Request $request, string $id): Response
    {
        $ended = $this->sessions->end($this->account(), Uuid::fromString($id), $request->getSession()->getId());

        return $ended ? new Response(null, Response::HTTP_NO_CONTENT) : new JsonResponse(['error' => 'session_not_found'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/api/auth/sessions', name: 'api_auth_sessions_end_others', methods: ['DELETE'])]
    public function endOthers(Request $request): Response
    {
        $ended = $this->sessions->endOthers($this->account(), $request->getSession()->getId());

        return new JsonResponse(['ended' => $ended]);
    }

    private function account(): User
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new \LogicException('The sessions endpoints run behind the firewall.');
        }

        return $this->users->ofId($account->getId()) ?? throw new \LogicException('A signed-in account has no user row.');
    }
}
