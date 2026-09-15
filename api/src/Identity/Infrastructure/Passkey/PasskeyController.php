<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Passkey;

use App\Identity\Application\Mfa\BeginPasskeyLogin;
use App\Identity\Application\Mfa\BeginPasskeyRegistration;
use App\Identity\Application\Mfa\FinishPasskeyLogin;
use App\Identity\Application\Mfa\LastSecondFactor;
use App\Identity\Application\Mfa\PasskeyNotFound;
use App\Identity\Application\Mfa\PasskeyRefused;
use App\Identity\Application\Mfa\RegisterPasskey;
use App\Identity\Application\Mfa\RemovePasskey;
use App\Identity\Domain\Passkey;
use App\Identity\Domain\PasskeyRepository;
use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Security\PendingSecondFactor;
use App\Identity\Infrastructure\Security\SecondFactorLogin;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Passkeys: managing them from a signed-in session, and answering the second half of a login with one.
 *
 * The two login endpoints are public in `access_control` for the reason /api/auth/mfa/verify is: no session exists yet,
 * and what authorises them is the pending marker the password step left. The login shares that endpoint's budget of
 * five attempts, since it is the same step paid another way.
 */
final readonly class PasskeyController
{
    private const string REGISTRATION = 'registration';
    private const string LOGIN = 'login';

    public function __construct(
        private Security $security,
        private PendingSecondFactor $pending,
        private PasskeyChallenges $challenges,
        private RateLimiterFactoryInterface $mfaVerifyLimiter,
        private UserRepository $users,
        private PasskeyRepository $passkeys,
        private BeginPasskeyRegistration $beginRegistration,
        private RegisterPasskey $registerPasskey,
        private RemovePasskey $removePasskey,
        private BeginPasskeyLogin $beginLogin,
        private FinishPasskeyLogin $finishLogin,
        private SecondFactorLogin $secondFactorLogin,
    ) {
    }

    #[Route('/api/auth/mfa/passkeys', name: 'api_auth_mfa_passkeys', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->users->ofId($this->currentUserId()) ?? throw new \LogicException('An authenticated account always has a user row.');

        return new JsonResponse(['passkeys' => array_map(self::describe(...), $this->passkeys->ofUser($user))]);
    }

    #[Route('/api/auth/mfa/passkeys/options', name: 'api_auth_mfa_passkeys_options', methods: ['POST'])]
    public function registrationOptions(): Response
    {
        $userId = $this->currentUserId();

        try {
            $options = $this->beginRegistration->handle($userId);
        } catch (PasskeyRefused) {
            return self::refused(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->challenges->remember(self::REGISTRATION, $userId, $options);

        return self::json($options);
    }

    #[Route('/api/auth/mfa/passkeys', name: 'api_auth_mfa_passkeys_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $userId = $this->currentUserId();
        // Taken before anything is checked, so a failed attempt spends the challenge as surely as a successful one.
        $options = $this->challenges->take(self::REGISTRATION, $userId);
        $body = self::body($request);
        $credential = self::credentialIn($body);

        if (null === $options || null === $credential) {
            return self::refused(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $registered = $this->registerPasskey->handle($userId, $options, $credential, \is_string($body['name'] ?? null) ? $body['name'] : '');
        } catch (PasskeyRefused) {
            return self::refused(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['passkey' => self::describe($registered->passkey), 'recoveryCodes' => $registered->recoveryCodes], Response::HTTP_CREATED);
    }

    #[Route('/api/auth/mfa/passkeys/{id}', name: 'api_auth_mfa_passkeys_remove', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    public function remove(string $id): Response
    {
        try {
            $this->removePasskey->handle($this->currentUserId(), Uuid::fromString($id));
        } catch (PasskeyNotFound) {
            return new JsonResponse(['error' => 'passkey_not_found'], Response::HTTP_NOT_FOUND);
        } catch (LastSecondFactor) {
            return new JsonResponse(['error' => 'mfa_last_factor'], Response::HTTP_CONFLICT);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/auth/mfa/passkey-login/options', name: 'api_auth_mfa_passkey_login_options', methods: ['POST'])]
    public function loginOptions(): Response
    {
        $userId = $this->pending->waiting();

        if (null === $userId) {
            return new JsonResponse(['error' => 'mfa_not_pending'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $options = $this->beginLogin->handle($userId);
        } catch (PasskeyRefused) {
            return self::refused(Response::HTTP_UNAUTHORIZED);
        }

        $this->challenges->remember(self::LOGIN, $userId, $options);

        return self::json($options);
    }

    #[Route('/api/auth/mfa/passkey-login', name: 'api_auth_mfa_passkey_login', methods: ['POST'])]
    public function login(Request $request): Response
    {
        $userId = $this->pending->waiting();

        if (null === $userId) {
            return new JsonResponse(['error' => 'mfa_not_pending'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->mfaVerifyLimiter->create($userId->toRfc4122())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $options = $this->challenges->take(self::LOGIN, $userId);
        $credential = self::credentialIn(self::body($request));

        if (null === $options || null === $credential) {
            return self::refused(Response::HTTP_UNAUTHORIZED);
        }

        try {
            $user = $this->finishLogin->handle($userId, $options, $credential);
        } catch (PasskeyRefused) {
            return self::refused(Response::HTTP_UNAUTHORIZED);
        }

        return $this->secondFactorLogin->complete($user);
    }

    private function currentUserId(): Uuid
    {
        $account = $this->security->getUser();

        if (!$account instanceof SecurityUser) {
            // access_control already requires ROLE_USER here, so this is a contradiction, not a user error.
            throw new \LogicException('The passkey management endpoints run behind the firewall.');
        }

        return $account->getId();
    }

    /** @return array{id: string, name: string, createdAt: string, lastUsedAt: string|null} */
    private static function describe(Passkey $passkey): array
    {
        return [
            'id' => $passkey->getId()->toRfc4122(),
            'name' => $passkey->getName(),
            'createdAt' => $passkey->getCreatedAt()->format(\DATE_ATOM),
            'lastUsedAt' => $passkey->getLastUsedAt()?->format(\DATE_ATOM),
        ];
    }

    private static function json(string $json): Response
    {
        return new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    private static function refused(int $status): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_passkey'], $status);
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

    /** @param array<array-key, mixed> $body */
    private static function credentialIn(array $body): ?string
    {
        return \is_array($body['credential'] ?? null) ? json_encode($body['credential'], \JSON_THROW_ON_ERROR) : null;
    }
}
