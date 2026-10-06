<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Signup;

use App\Identity\Domain\Email;
use App\Tenancy\Application\Signup\RequestSignup;
use App\Tenancy\Application\Signup\SignupClosed;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** The worker's side of a signup ask: the address looked up, the link or the "you have an account" mail sent. */
#[AsMessageHandler]
final readonly class SignupAskedHandler
{
    public function __construct(private RequestSignup $request)
    {
    }

    public function __invoke(SignupAsked $asked): void
    {
        try {
            $this->request->handle(Email::fromString($asked->email), $asked->locale);
        } catch (SignupClosed) {
            // Closed between the ask and the work: a shut signup offers no link, and retrying would not open it.
        }
    }
}
