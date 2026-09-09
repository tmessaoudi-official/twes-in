<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * A failed login answers JSON with a stable error code. Unknown email and wrong password are the same code:
 * the response must not reveal which accounts exist. A locked or disabled account says so, since only its
 * owner (who has just supplied the right credentials, or is about to) needs to know.
 */
final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public const string INVALID_CREDENTIALS = 'invalid_credentials';
    public const string TOO_MANY_ATTEMPTS = 'too_many_attempts';

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return match (true) {
            $exception instanceof TooManyLoginAttemptsAuthenticationException => new JsonResponse(['error' => self::TOO_MANY_ATTEMPTS], Response::HTTP_TOO_MANY_REQUESTS),
            $exception instanceof CustomUserMessageAccountStatusException => new JsonResponse(['error' => $exception->getMessageKey()], Response::HTTP_UNAUTHORIZED),
            default => new JsonResponse(['error' => self::INVALID_CREDENTIALS], Response::HTTP_UNAUTHORIZED),
        };
    }
}
