<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\Login\FailedLoginAttempt;
use App\Identity\Application\Login\RecordFailedLogin;
use App\Identity\Application\Login\RecordLogout;
use App\Identity\Application\Login\RecordSuccessfulLogin;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Symfony authentication events, translated into the Identity use cases. */
final readonly class AuthenticationEventsListener
{
    public function __construct(
        private RecordSuccessfulLogin $recordSuccessfulLogin,
        private RecordFailedLogin $recordFailedLogin,
        private RecordLogout $recordLogout,
    ) {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $account = $event->getUser();
        if ($account instanceof SecurityUser) {
            $this->recordSuccessfulLogin->handle($account->getId());
        }
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $exception = $event->getException();

        // A login that owes a second factor is not a failed attempt: the password was right. Counting it
        // would let anyone lock an account out by supplying its correct password five times.
        if ($exception instanceof SecondFactorRequired) {
            return;
        }

        $userId = null;
        try {
            $resolved = $event->getPassport()?->getUser();
            $userId = $resolved instanceof SecurityUser ? $resolved->getId() : null;
        } catch (\Throwable) {
            // No such account: the passport cannot resolve a user.
        }
        $reason = $exception instanceof TooManyLoginAttemptsAuthenticationException ? 'throttled' : (new \ReflectionClass($exception))->getShortName();

        $this->recordFailedLogin->handle(new FailedLoginAttempt($userId, self::attemptedEmail($event), $reason, $exception instanceof BadCredentialsException));
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $account = $event->getToken()?->getUser();
        if ($account instanceof SecurityUser) {
            $this->recordLogout->handle($account->getId());
        }
        $event->setResponse(new Response(null, Response::HTTP_NO_CONTENT));
    }

    private static function attemptedEmail(LoginFailureEvent $event): ?string
    {
        $raw = $event->getRequest()->getContent();
        if ('' === $raw) {
            return null;
        }
        try {
            $body = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($body) && \is_string($body['email'] ?? null) ? mb_substr(mb_strtolower(trim($body['email'])), 0, 254) : null;
    }
}
