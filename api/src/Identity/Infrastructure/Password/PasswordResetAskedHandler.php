<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

use App\Identity\Application\Password\RequestPasswordReset;
use App\Identity\Domain\Email;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** The worker's side of a forgotten password: the account looked up, the link made and mailed, away from the request. */
#[AsMessageHandler]
final readonly class PasswordResetAskedHandler
{
    public function __construct(private RequestPasswordReset $request)
    {
    }

    public function __invoke(PasswordResetAsked $asked): void
    {
        $this->request->handle(Email::fromString($asked->email), $asked->locale);
    }
}
