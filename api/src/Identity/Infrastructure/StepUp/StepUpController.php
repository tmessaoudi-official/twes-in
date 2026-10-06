<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\StepUp;

use App\Identity\Application\Mfa\BeginPasskeyAssertion;
use App\Identity\Application\Mfa\PasskeyRefused;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRefused;
use App\Identity\Infrastructure\Passkey\PasskeyChallenges;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Proving who is at the screen again, with the password or a passkey. The answer is only yes or no: what the proof
 * unlocks is the screen's to decide. A proof holds a few minutes, so several files in a row ask once (docs/SPEC.md
 * § 7, H-b2); leaving the customer screen spends it, or the customer next at the screen would ride on it.
 *
 * Both proofs draw on the budget of five attempts the second factor of a login has, keyed on the account: a password
 * that could be guessed here at the limiter's pace is guessed eventually, and the right one does not get round it.
 */
final readonly class StepUpController
{
    private const string PURPOSE = 'step_up';

    public function __construct(
        private Security $security,
        private PasskeyChallenges $challenges,
        #[Target('mfa_verify')]
        private RateLimiterFactoryInterface $limiter,
        private ConfirmStepUp $confirm,
        private BeginPasskeyAssertion $beginAssertion,
    ) {
    }

    #[Route('/api/auth/step-up', name: 'api_auth_step_up', methods: ['POST'])]
    public function password(Request $request): Response
    {
        $userId = $this->currentUserId();

        if (!$this->limiter->create($userId->toRfc4122())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $password = self::body($request)['password'] ?? null;

        try {
            $this->confirm->withPassword($userId, \is_string($password) ? $password : '');
        } catch (StepUpRefused) {
            return new JsonResponse(['error' => 'invalid_credentials'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/auth/step-up/passkey/options', name: 'api_auth_step_up_passkey_options', methods: ['POST'])]
    public function passkeyOptions(): Response
    {
        $userId = $this->currentUserId();

        try {
            $options = $this->beginAssertion->handle($userId);
        } catch (PasskeyRefused) {
            return new JsonResponse(['error' => 'invalid_passkey'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->challenges->remember(self::PURPOSE, $userId, $options);

        return new Response($options, Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    #[Route('/api/auth/step-up/passkey', name: 'api_auth_step_up_passkey', methods: ['POST'])]
    public function passkey(Request $request): Response
    {
        $userId = $this->currentUserId();

        if (!$this->limiter->create($userId->toRfc4122())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Taken before anything is checked, so a failed attempt spends the challenge as surely as a successful one.
        $options = $this->challenges->take(self::PURPOSE, $userId);
        $credential = self::body($request)['credential'] ?? null;

        if (null === $options || !\is_array($credential)) {
            return new JsonResponse(['error' => 'invalid_passkey'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->confirm->withPasskey($userId, $options, json_encode($credential, \JSON_THROW_ON_ERROR));
        } catch (StepUpRefused) {
            return new JsonResponse(['error' => 'invalid_passkey'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function currentUserId(): Uuid
    {
        $account = $this->security->getUser();

        if (!$account instanceof SecurityUser) {
            // access_control already requires ROLE_USER here, so this is a contradiction, not a user error.
            throw new \LogicException('The step-up endpoints run behind the firewall.');
        }

        return $account->getId();
    }

    /** @return array<array-key, mixed> */
    private static function body(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($body) ? $body : [];
    }
}
