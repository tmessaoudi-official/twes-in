<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

use App\Identity\Domain\UserRepository;
use App\Tenancy\Domain\Signup;
use App\Tenancy\Domain\SignupRepository;
use App\Tenancy\Domain\SignupToken;
use Psr\Clock\ClockInterface;

/**
 * Whether a link can still be used. Every reason it cannot is the same null: signup closed since it was sent, a value
 * that is not a token, an unknown one, an expired or used one, and an address that has gained an account since. A link
 * never sets the password of an account that exists, because a mailed link that could is an account takeover.
 */
final readonly class SignupLinks
{
    public function __construct(
        private SignupPolicy $policy,
        private SignupRepository $signups,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function usable(string $rawToken): ?Signup
    {
        if (!$this->policy->isOpen()) {
            return null;
        }
        try {
            $token = SignupToken::fromRaw($rawToken);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $signup = $this->signups->ofTokenHash($token->hash());
        if (null === $signup || !$signup->isUsableAt($this->clock->now()) || null !== $this->users->ofEmail($signup->getEmail())) {
            return null;
        }

        return $signup;
    }
}
