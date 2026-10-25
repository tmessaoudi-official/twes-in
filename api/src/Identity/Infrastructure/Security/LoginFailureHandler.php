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
 * A failed login answers JSON with a stable error code. The one exception is a login that owes a second
 * factor: the credentials were accepted, so it answers 200 and the SPA asks for the code.
 *
 * A failed login answers JSON with a stable error code. Unknown email and wrong password are the same code:
 * the response must not reveal which accounts exist. So is a locked account, right password or wrong
 * (`LockedAccountRefused`): its owner is told by mail. A disabled account says so once the password is right.
 */
final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public const string INVALID_CREDENTIALS = 'invalid_credentials';
    public const string TOO_MANY_ATTEMPTS = 'too_many_attempts';

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return match (true) {
            // The password was right and one step is left, so this is not a refusal: 200, and the SPA asks
            // for the code. No session exists yet either way (ruling of 2026-09-10).
            $exception instanceof SecondFactorRequired => new JsonResponse(['mfaRequired' => true]),
            $exception instanceof TooManyLoginAttemptsAuthenticationException => new JsonResponse(['error' => self::TOO_MANY_ATTEMPTS], Response::HTTP_TOO_MANY_REQUESTS),
            $exception instanceof CustomUserMessageAccountStatusException => new JsonResponse(['error' => $exception->getMessageKey()], Response::HTTP_UNAUTHORIZED),
            default => new JsonResponse(['error' => self::INVALID_CREDENTIALS], Response::HTTP_UNAUTHORIZED),
        };
    }
}
