<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

use App\Identity\Application\Password\NewPasswordPolicy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * How long a new password must be, read by every screen choosing one (signing up, accepting an invitation, a reset by
 * mail, « Mon compte »), signed in or not, so a password the API would refuse is said under its field as it is typed,
 * never only after it was sent. Whether it appeared in a breach is the API's alone to say.
 */
final readonly class PasswordRuleController
{
    #[Route('/api/auth/password/rule', name: 'api_auth_password_rule', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['minLength' => NewPasswordPolicy::MIN_LENGTH, 'maxLength' => NewPasswordPolicy::MAX_LENGTH]);
    }
}
