<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\CompanyProof;

use App\Identity\Application\CompanyProof\EntryProofRefused;
use App\Identity\Application\CompanyProof\ProveCompanyEntry;
use App\Identity\Application\StepUp\StepUpExhausted;
use App\Identity\Infrastructure\Passkey\PasskeyChallenges;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Application\Member\NotAMember;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The proof a member of several companies gives once in a sign-in to enter one: the password, with the authenticator
 * code where the company requires a second factor, or a passkey where it takes one. A company that is not the caller's
 * answers 404 as everywhere; a wrong answer 422 with its reason, and the last one this sign-in may give ends it.
 */
final readonly class CompanyProofController
{
    private const string PURPOSE = 'company_proof';

    public function __construct(
        private Security $security,
        private ProveCompanyEntry $prove,
        private PasskeyChallenges $challenges,
        #[Target('mfa_verify')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/companies/{companyId}/proof', name: 'api_company_proof', requirements: ['companyId' => Requirement::UUID], methods: ['POST'])]
    public function password(string $companyId, Request $request): Response
    {
        $userId = $this->currentUserId();
        $attempts = $this->limiter->create($userId->toRfc4122());
        if (!$attempts->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $body = self::body($request);
        $password = $body['password'] ?? null;
        $code = $body['code'] ?? null;

        return $this->answer($attempts, fn () => $this->prove->withPassword($userId, Uuid::fromString($companyId), \is_string($password) ? $password : '', \is_string($code) ? $code : null));
    }

    #[Route('/api/companies/{companyId}/proof/passkey/options', name: 'api_company_proof_passkey_options', requirements: ['companyId' => Requirement::UUID], methods: ['POST'])]
    public function passkeyOptions(string $companyId): Response
    {
        $userId = $this->currentUserId();
        try {
            $options = $this->prove->passkeyOptions($userId, Uuid::fromString($companyId));
        } catch (NotAMember) {
            return self::noSuchCompany();
        } catch (EntryProofRefused) {
            return new JsonResponse(['error' => EntryProofRefused::PASSKEY_NOT_TAKEN], Response::HTTP_FORBIDDEN);
        }
        $this->challenges->remember(self::PURPOSE, $userId, $options);

        return new Response($options, Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    #[Route('/api/companies/{companyId}/proof/passkey', name: 'api_company_proof_passkey', requirements: ['companyId' => Requirement::UUID], methods: ['POST'])]
    public function passkey(string $companyId, Request $request): Response
    {
        $userId = $this->currentUserId();
        $attempts = $this->limiter->create($userId->toRfc4122());
        if (!$attempts->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        // Taken before anything is checked, so a failed attempt spends the challenge as surely as a successful one.
        $options = $this->challenges->take(self::PURPOSE, $userId);
        $credential = self::body($request)['credential'] ?? null;
        if (null === $options || !\is_array($credential)) {
            return new JsonResponse(['error' => EntryProofRefused::PASSKEY], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->answer($attempts, fn () => $this->prove->withPasskey($userId, Uuid::fromString($companyId), $options, json_encode($credential, \JSON_THROW_ON_ERROR)));
    }

    /**
     * An attempt is spent before anything is checked, so parallel guesses cannot outrun the budget; a proof that holds
     * gives it back, since the budget is shared with every other answer to who is at the screen and is there to count
     * guesses, not entries.
     *
     * @param \Closure(): void $proof
     */
    private function answer(LimiterInterface $attempts, \Closure $proof): Response
    {
        try {
            $proof();
            $attempts->reset();
        } catch (NotAMember) {
            return self::noSuchCompany();
        } catch (EntryProofRefused $refused) {
            return new JsonResponse(['error' => $refused->reason], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (StepUpExhausted) {
            // The sign-in spent its wrong answers: it ends as a logout would, and the account stays open for a new one.
            $this->security->logout(false);

            return new JsonResponse(['error' => 'step_up_exhausted'], Response::HTTP_UNAUTHORIZED);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private static function noSuchCompany(): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

    private function currentUserId(): Uuid
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // access_control requires ROLE_USER under /api, so this is a contradiction, not a user error.
            throw new \LogicException('The company proof runs behind the firewall.');
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
