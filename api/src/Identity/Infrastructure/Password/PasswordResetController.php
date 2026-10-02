<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

use App\Identity\Application\Password\NewPasswordRefused;
use App\Identity\Application\Password\RequestPasswordReset;
use App\Identity\Application\Password\ResetLinkNotUsable;
use App\Identity\Application\Password\ResetPassword;
use App\Identity\Domain\Email;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Forgot password, with no session: an address asks for a mailed link, and the link's holder chooses a new password.
 * Asking is answered 204 whatever the address (an unknown one, a deactivated one, one already mailed a quarter of an
 * hour ago), so the answer never says whether an account exists; only the client budget answers differently, 429.
 */
final readonly class PasswordResetController
{
    public function __construct(
        private RequestPasswordReset $request,
        private ResetPassword $reset,
        #[Target('password_reset_client')]
        private RateLimiterFactoryInterface $clientLimiter,
        #[Target('password_reset_address')]
        private RateLimiterFactoryInterface $addressLimiter,
        #[Target('password_reset_use')]
        private RateLimiterFactoryInterface $useLimiter,
    ) {
    }

    #[Route('/api/auth/password/forgot', name: 'api_auth_password_forgot', methods: ['POST'])]
    public function forgot(Request $request): Response
    {
        if (!$this->clientLimiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $body = self::body($request);
        try {
            $email = Email::fromString(\is_string($body['email'] ?? null) ? $body['email'] : '');
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'invalid_email'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->addressLimiter->create(hash('sha256', $email->value))->consume()->isAccepted()) {
            $this->request->handle($email, \is_string($body['locale'] ?? null) ? $body['locale'] : null);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/auth/password/reset', name: 'api_auth_password_reset', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        if (!$this->useLimiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $body = self::body($request);
        $token = \is_string($body['token'] ?? null) ? $body['token'] : '';
        $password = \is_string($body['newPassword'] ?? null) ? $body['newPassword'] : '';

        try {
            $this->reset->handle($token, $password);
        } catch (ResetLinkNotUsable) {
            return new JsonResponse(['error' => 'link_not_usable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (NewPasswordRefused $refused) {
            return new JsonResponse(['error' => $refused->reason], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<array-key, mixed> */
    private static function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true);

        return \is_array($body) ? $body : [];
    }
}
