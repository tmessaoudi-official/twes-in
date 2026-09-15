<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** The end of a login that owed a second factor, whichever factor paid it. */
final readonly class SecondFactorLogin
{
    public function __construct(
        private PendingSecondFactor $pending,
        private Security $security,
    ) {
    }

    public function complete(User $user): Response
    {
        // Only now does a session exist. `login()` dispatches the success event, so the audit row and the session id
        // rotation are the same ones an MFA-less login gets.
        $this->pending->close();

        return $this->security->login(SecurityUser::of($user), 'json_login')
            ?? new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
