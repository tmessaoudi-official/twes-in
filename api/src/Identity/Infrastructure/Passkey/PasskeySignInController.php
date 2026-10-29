<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Passkey;

use App\Identity\Application\Login\PasskeySignInRefused;
use App\Identity\Application\Login\SignInWithPasskey;
use App\Identity\Application\Login\SwitchPasskeySignIn;
use App\Identity\Application\Mfa\Passkey\PasskeyRefused;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Infrastructure\Security\SecondFactorLogin;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\UserChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccountStatusException;

/**
 * Signing in with a passkey alone, and the signed-in account's own switch of it. The two sign-in endpoints are public
 * in `access_control`: no session exists yet, and the device's passkey is the whole proof. Each client has its own
 * budget of tries, since there is no account to count against before the passkey is proved.
 */
final readonly class PasskeySignInController
{
    private const string SIGN_IN = 'sign_in';

    public function __construct(
        private Security $security,
        private SwitchPasskeySignIn $switch,
        private SignInWithPasskey $signIn,
        private PasskeyChallenges $challenges,
        private UserChecker $checker,
        private SecondFactorLogin $login,
        #[Target('passkey_sign_in')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/auth/passkey-sign-in/options', name: 'api_auth_passkey_sign_in_options', methods: ['POST'])]
    public function options(): Response
    {
        $options = $this->signIn->options();
        $this->challenges->remember(self::SIGN_IN, null, $options);

        return new JsonResponse($options, Response::HTTP_OK, [], true);
    }

    #[Route('/api/auth/passkey-sign-in', name: 'api_auth_passkey_sign_in', methods: ['POST'])]
    public function signIn(Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        // Taken before anything is checked, so a failed attempt spends the challenge as surely as a successful one.
        $options = $this->challenges->take(self::SIGN_IN, null);
        try {
            $body = json_decode($request->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }
        $credential = \is_array($body) && \is_array($body['credential'] ?? null) ? json_encode($body['credential'], \JSON_THROW_ON_ERROR) : null;
        if (null === $options || null === $credential) {
            return self::refused();
        }

        try {
            $user = $this->signIn->handle($options, $credential);
            // A deactivated account hears why only once its passkey is proved, as it does once its password is right.
            $this->checker->checkPostAuth(SecurityUser::of($user));
        } catch (PasskeyRefused) {
            return self::refused();
        } catch (AccountStatusException $refused) {
            return new JsonResponse(['error' => $refused->getMessageKey()], Response::HTTP_UNAUTHORIZED);
        }

        return $this->login->complete($user);
    }

    #[Route('/api/auth/mfa/passkey-sign-in', name: 'api_auth_mfa_passkey_sign_in', methods: ['PUT'])]
    public function switch(Request $request): Response
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // access_control requires ROLE_USER here, so this is a contradiction, not a user error.
            throw new \LogicException('The switch runs behind the firewall.');
        }
        try {
            $body = json_decode($request->getContent(), true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }
        $enabled = \is_array($body) ? $body['enabled'] ?? null : null;
        if (!\is_bool($enabled)) {
            return new JsonResponse(['error' => 'enabled_required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->switch->handle($account->getId(), $enabled);
        } catch (StepUpRequired) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        } catch (PasskeySignInRefused $refused) {
            return new JsonResponse(['error' => $refused->reason], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private static function refused(): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_passkey'], Response::HTTP_UNAUTHORIZED);
    }
}
