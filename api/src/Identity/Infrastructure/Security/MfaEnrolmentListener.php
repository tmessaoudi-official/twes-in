<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\Mfa\SecondFactors;
use App\Identity\Domain\UserRepository;
use App\Tenancy\Application\Mfa\MfaRequirement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Holds a signed-in account at the door when a company it belongs to requires a second factor it has not
 * enrolled.
 *
 * Without this the requirement would be advice the SPA is free to ignore. The refusal names itself rather
 * than looking like a permission problem, and the few routes that lead OUT of the state stay open — otherwise
 * the account would be locked in a room with no door.
 */
#[AsEventListener(priority: 4)]
final readonly class MfaEnrolmentListener
{
    /**
     * The way out: read who you are, enrol, and leave. Everything else waits.
     *
     * Matched on paths, not route names: API Platform generates its own names (`/api/auth/me` is
     * `_api_/auth/me_get`), and a list of names is a list that goes stale silently the next time a resource
     * is renamed. A path is the thing the rule is actually about.
     */
    private const array ALLOWED_EXACTLY = ['/api/auth/me', '/api/auth/logout', '/api/health'];

    private const string ALLOWED_PREFIX = '/api/auth/mfa/';

    public function __construct(
        private Security $security,
        private UserRepository $users,
        private MfaRequirement $requirement,
        private SecondFactors $secondFactors,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $path = rtrim($request->getPathInfo(), '/');

        if (!str_starts_with($path, '/api')
            || \in_array($path, self::ALLOWED_EXACTLY, true)
            || str_starts_with($request->getPathInfo(), self::ALLOWED_PREFIX)
        ) {
            return;
        }

        $account = $this->security->getUser();

        if (!$account instanceof SecurityUser) {
            return;
        }

        $user = $this->users->ofId($account->getId());

        if (null === $user || $this->secondFactors->has($user) || !$this->requirement->appliesTo($user)) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => 'mfa_enrolment_required'], Response::HTTP_FORBIDDEN));
    }
}
