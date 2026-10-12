<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\Mfa\SecondFactors;
use App\Identity\Domain\UserRepository;
use App\Shared\Infrastructure\Http\RoutedPath;
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
     * The way out: read who you are, prove it again (adding a factor asks for that), enrol, and leave. Everything else waits.
     *
     * Matched on paths, not route names: API Platform generates its own names (`/api/auth/me` is
     * `_api_/auth/me_get`), and a list of names is a list that goes stale silently the next time a resource
     * is renamed. A path is the thing the rule is actually about.
     */
    private const array ALLOWED_EXACTLY = ['/api/auth/me', '/api/auth/logout', '/api/health', '/api/auth/step-up', '/api/auth/step-up/passkey/options', '/api/auth/step-up/passkey'];

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

        $path = rtrim(RoutedPath::of($request), '/');

        if (!str_starts_with($path, '/api')
            || \in_array($path, self::ALLOWED_EXACTLY, true)
            || str_starts_with(RoutedPath::of($request), self::ALLOWED_PREFIX)
        ) {
            return;
        }

        // Only a session holds a signed-in account: a request bringing none has nothing to hold, and asking who is signed
        // in would make the lazy firewall read a session for it, which turns a public answer's cache header private.
        if (!$request->hasPreviousSession()) {
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
