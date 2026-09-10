<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Application\Mfa\MfaRequirement;
use App\Tenancy\Application\Session\DescribeWorkingContext;

/** The signed-in state of the authenticated account: the user row plus the Tenancy working context. */
final readonly class MeFactory
{
    public function __construct(
        private UserRepository $users,
        private DescribeWorkingContext $describeWorkingContext,
        private MfaRequirement $mfaRequirement,
    ) {
    }

    public function for(SecurityUser $account): Me
    {
        $user = $this->users->ofId($account->getId()) ?? throw new \LogicException('An authenticated account always has a user row.');

        return Me::of($user, $this->describeWorkingContext->for($user->getId()), $this->mfaRequirement->appliesTo($user));
    }
}
