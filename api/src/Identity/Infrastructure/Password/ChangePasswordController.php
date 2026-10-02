<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

use App\Identity\Application\Password\ChangePassword;
use App\Identity\Application\Password\NewPasswordRefused;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A signed-in person changes their own password. The current one is checked here, so the attempts draw on the same
 * budget of five as a step-up and the second factor of a login, keyed on the account: a session left open at a screen
 * must not let a stranger guess the password at the limiter's pace. The answer is 204, after which the person's
 * sessions are over, this one included.
 */
final readonly class ChangePasswordController
{
    public function __construct(
        private Security $security,
        #[Target('mfa_verify')]
        private RateLimiterFactoryInterface $limiter,
        private ChangePassword $change,
    ) {
    }

    #[Route('/api/auth/password', name: 'api_auth_password_change', methods: ['PUT'])]
    public function __invoke(Request $request): Response
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new \LogicException('The password endpoint runs behind the firewall.');
        }

        if (!$this->limiter->create($account->getId()->toRfc4122())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $body = json_decode($request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        $current = $body['currentPassword'] ?? null;
        $new = $body['newPassword'] ?? null;

        try {
            $this->change->handle($account->getId(), \is_string($current) ? $current : '', \is_string($new) ? $new : '');
        } catch (NewPasswordRefused $refused) {
            return new JsonResponse(['error' => $refused->reason], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
