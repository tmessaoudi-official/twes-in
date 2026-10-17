<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Http;

use App\Demo\Application\ClaimSandbox;
use App\Demo\Application\DemoSwitchedOff;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Essayer la démo »: a visitor with no account picks a country and is signed in as the owner of the sandbox ready for
 * it, with no password, and answered as a sign-in is. The sign-in is a real one (its audit row, its session), only the
 * account is a throwaway. While the worker is still building the next one, the visitor is asked to come back shortly.
 */
final readonly class DemoEntryController
{
    public const string SWITCHED_OFF = 'demo_off';
    public const string BUSY = 'demo_busy';
    public const string UNKNOWN_COUNTRY = 'unknown_country';
    /** A build takes under a minute: the screen tries again after this many seconds. */
    private const int TRY_AGAIN_AFTER = 30;

    public function __construct(
        private ClaimSandbox $claims,
        private UserRepository $users,
        private Security $security,
        #[Target('demo_entry')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/auth/demo', name: 'api_auth_demo', methods: ['POST'])]
    public function enter(Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $body = json_decode($request->getContent(), true);
        $country = \is_array($body) && \is_string($body['country'] ?? null) ? $body['country'] : '';

        try {
            $claimed = $this->claims->handle($country);
        } catch (DemoSwitchedOff) {
            return new JsonResponse(['error' => self::SWITCHED_OFF], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => self::UNKNOWN_COUNTRY], Response::HTTP_BAD_REQUEST);
        }
        if (null === $claimed) {
            return new JsonResponse(['error' => self::BUSY], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => (string) self::TRY_AGAIN_AFTER]);
        }
        $owner = $this->users->ofId($claimed->ownerId) ?? throw new \LogicException('A claimed sandbox has its owner.');

        return $this->security->login(SecurityUser::of($owner), 'json_login')
            ?? throw new \LogicException('The sign-in answers what a sign-in with a password does.');
    }
}
