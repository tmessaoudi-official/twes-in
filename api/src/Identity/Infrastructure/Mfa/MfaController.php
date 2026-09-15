<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mfa;

use App\Identity\Application\Mfa\BeginTotpEnrolment;
use App\Identity\Application\Mfa\ConfirmTotpEnrolment;
use App\Identity\Application\Mfa\RegenerateRecoveryCodes;
use App\Identity\Application\Mfa\SecondFactorAlreadyEnrolled;
use App\Identity\Application\Mfa\SecondFactorRefused;
use App\Identity\Application\Mfa\VerifySecondFactor;
use App\Identity\Infrastructure\Security\PendingSecondFactor;
use App\Identity\Infrastructure\Security\SecondFactorLogin;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The MFA endpoints: the second half of a login, and enrolling a factor in the first place.
 *
 * POST /api/auth/mfa/verify — the second half of a login.
 *
 * Public in `access_control` because by design no session exists yet: what authorises it is the pending
 * marker the password step left in this very session, not a role. Throttled hard, because six digits with a
 * step of leeway is a small space to guess in.
 */
final readonly class MfaController
{
    public function __construct(
        private PendingSecondFactor $pending,
        private VerifySecondFactor $verify,
        private Security $security,
        private RateLimiterFactoryInterface $mfaVerifyLimiter,
        private BeginTotpEnrolment $beginEnrolment,
        private ConfirmTotpEnrolment $confirmEnrolment,
        private RegenerateRecoveryCodes $regenerateRecoveryCodes,
        private SecondFactorLogin $secondFactorLogin,
    ) {
    }

    #[Route('/api/auth/mfa/verify', name: 'api_auth_mfa_verify', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $userId = $this->pending->waiting();

        if (null === $userId) {
            // No password step, or it expired. Says nothing about whether the account exists or has MFA.
            return new JsonResponse(['error' => 'mfa_not_pending'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->mfaVerifyLimiter->create($userId->toRfc4122())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $user = $this->verify->handle($userId, self::codeIn($request));
        } catch (SecondFactorRefused) {
            return new JsonResponse(['error' => 'invalid_code'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->secondFactorLogin->complete($user);
    }

    /** Step one of enrolment. Needs a full session: this changes the account, so it is not part of signing in. */
    #[Route('/api/auth/mfa/enrolment', name: 'api_auth_mfa_enrolment', methods: ['POST'])]
    public function begin(): JsonResponse
    {
        try {
            $enrolment = $this->beginEnrolment->handle($this->currentUserId());
        } catch (SecondFactorAlreadyEnrolled) {
            return new JsonResponse(['error' => 'mfa_already_enrolled'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['secret' => $enrolment->secret, 'provisioningUri' => $enrolment->provisioningUri]);
    }

    /** Step two: the code proves the authenticator agrees, and the recovery codes come back once. */
    #[Route('/api/auth/mfa/enrolment/confirm', name: 'api_auth_mfa_enrolment_confirm', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        try {
            $codes = $this->confirmEnrolment->handle($this->currentUserId(), self::codeIn($request));
        } catch (SecondFactorRefused) {
            return new JsonResponse(['error' => 'invalid_code'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['recoveryCodes' => $codes]);
    }

    /**
     * A new set of recovery codes against a current authenticator code. Throttled on the same budget as the login code,
     * because it is the same six digits to guess, and a guess here that lands hands over all ten codes.
     */
    #[Route('/api/auth/mfa/recovery-codes', name: 'api_auth_mfa_recovery_codes', methods: ['POST'])]
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $userId = $this->currentUserId();

        if (!$this->mfaVerifyLimiter->create($userId->toRfc4122())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $codes = $this->regenerateRecoveryCodes->handle($userId, self::codeIn($request));
        } catch (SecondFactorRefused) {
            return new JsonResponse(['error' => 'invalid_code'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['recoveryCodes' => $codes]);
    }

    private function currentUserId(): \Symfony\Component\Uid\Uuid
    {
        $account = $this->security->getUser();

        if (!$account instanceof SecurityUser) {
            // access_control already requires ROLE_USER here, so this is a contradiction, not a user error.
            throw new \LogicException('The MFA enrolment endpoints run behind the firewall.');
        }

        return $account->getId();
    }

    private static function codeIn(Request $request): string
    {
        try {
            $body = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        return \is_array($body) && \is_string($body['code'] ?? null) ? $body['code'] : '';
    }
}
